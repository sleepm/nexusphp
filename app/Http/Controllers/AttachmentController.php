<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Nexus\Attachment\Storage;
use Nexus\Database\NexusDB;

/**
 * Attachment upload iframe + file download.
 *
 * Mirrors legacy public/attachment.php (the tiny inline iframe used by the
 * editor to attach a file and inject [attach]dlkey[/attach] / preview callbacks
 * into the parent page) and public/getattachment.php (the raw file download).
 *
 * GET/POST /attachment.php        -> upload form + handling
 * GET     /getattachment.php?id=&dlkey= -> stream the stored file
 */
class AttachmentController extends Controller
{
    private AttachmentService $attachmentService;

    public function __construct(AttachmentService $attachmentService)
    {
        $this->attachmentService = $attachmentService;
    }

    /**
     * Upload iframe. GET renders the form; POST validates the uploaded file,
     * stores it (local folder or remote image driver) and returns the parent
     * window JS callback followed by the (re-rendered) form.
     */
    public function webUpload(Request $request)
    {
        /** @var User|null $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            return redirect()->guest('/login.php');
        }
        if ($currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $curUser = $currentUser->toArray();
        $this->bootstrapGlobals($curUser);

        $lang = get_legacy_lang_file('attachment');
        $callbackFunc = (string) ($request->input('callback_func', '') ?? '');
        $altsize = (string) ($request->input('altsize', '') ?? '');

        $attachmentEnabled = $this->attachmentService->isEnabled();
        $countLimit = $this->attachmentService->getCountLimit((int) ($curUser['class'] ?? 0));
        $countLeft = $this->attachmentService->getCountLeft((int) $curUser['id']);
        $sizeLimit = $this->attachmentService->getSizeLimitByte((int) ($curUser['class'] ?? 0));
        $allowedExts = $this->attachmentService->getAllowedExts((int) ($curUser['class'] ?? 0));

        $warning = '';
        $callbackHtml = '';

        if ($attachmentEnabled && $request->isMethod('POST')) {
            [$warning, $callbackHtml, $countLeft] = $this->handleUpload($request, $curUser, $lang, $countLeft, $sizeLimit, $allowedExts, $altsize, $callbackFunc);
        }

        return view('attachment', [
            'callbackHtml' => $callbackHtml,
            'warning' => $warning,
            'lang' => $lang,
            'countLeft' => $countLeft,
            'countLimit' => $countLimit,
            'sizeLimit' => $sizeLimit,
            'allowedExts' => $allowedExts,
            'altsize' => $altsize,
            'callbackFunc' => $callbackFunc,
            'attachmentEnabled' => $attachmentEnabled,
        ]);
    }

    /**
     * Attachment download. Mirrors legacy public/getattachment.php.
     */
    public function webDownload(Request $request)
    {
        $id = (int) $request->query('id', 0);
        if (! $id) {
            abort(404, 'Invalid id.');
        }
        $dlkey = (string) $request->query('dlkey', '');
        if ($dlkey === '') {
            abort(404, 'Invalid key');
        }

        $attachment = Attachment::query()
            ->where('id', $id)
            ->where('dlkey', $dlkey)
            ->first();
        if (! $attachment) {
            abort(404, 'No attachment found.');
        }

        $driver = $attachment->driver ?: 'local';
        if ($driver !== 'local') {
            $url = Storage::getDriver($driver)->getImageUrl($attachment->location);
            $this->incrementDownloads($attachment);

            return redirect($url);
        }

        $httpdirectory = get_setting('attachment.httpdirectory', 'attachments');
        $filelocation = $httpdirectory . '/' . $attachment->location;
        $fullpath = getFullDirectory($filelocation);
        if (! is_file($fullpath) || ! is_readable($fullpath)) {
            abort(404, 'File not found or cannot be read.');
        }
        $f = fopen($fullpath, 'rb');
        if (! $f) {
            abort(500, 'Cannot open file');
        }

        $this->incrementDownloads($attachment);

        return response()->stream(function () use ($f) {
            while (! feof($f)) {
                echo fread($f, 4096);
                flush();
            }
            fclose($f);
        }, 200, [
            'Content-Length' => $attachment->filesize,
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => make_content_disposition($attachment->filename),
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: int} [$warning, $callbackHtml, $countLeft]
     */
    private function handleUpload(Request $request, array $curUser, array $lang, int $countLeft, int $sizeLimit, array $allowedExts, string $altsize, string $callbackFunc): array
    {
        $file = $request->file('file');
        $filesize = $file ? (int) $file->getSize() : 0;
        $filetype = $file ? (string) $file->getMimeType() : '';
        $origfilename = $file ? (string) $file->getClientOriginalName() : '';
        $tmpName = $file ? $file->getPathname() : '';
        $ext_l = strrpos($origfilename, '.');
        $ext = $ext_l === false ? '' : strtolower(substr($origfilename, $ext_l + 1, strlen($origfilename) - ($ext_l + 1)));
        $bannedExt = $this->attachmentService->getBannedExts();
        $imgExt = Attachment::IMG_EXTENSIONS;

        if (! $file || $filesize == 0 || $origfilename == '') {
            $warning = $lang['text_nothing_received'];
        } elseif (! $countLeft) {
            $warning = $lang['text_file_number_limit_reached'];
        } elseif ($filesize > $sizeLimit || $filesize >= 5242880) {
            $warning = $lang['text_file_size_too_big'];
        } elseif (! in_array($ext, $allowedExts) || in_array($ext, $bannedExt)) {
            $warning = $lang['text_file_extension_not_allowed'];
        } else {
            $isimage = in_array($ext, $imgExt);
            $width = 0;
            $height = 0;
            $imagesize = [];
            if ($isimage) {
                $imagesize = getimagesize($tmpName);
                $height = $imagesize[1];
                $width = $imagesize[0];
            }
            $storageDriver = $this->attachmentService->getStorageDriver();
            $location = '';
            $url = '';
            $hasthumb = false;
            if ($storageDriver == 'local' || ! $isimage) {
                [$warning, $location, $url, $hasthumb, $filetype, $filesize, $isimage, $width] = $this->storeLocal($request, $file, $tmpName, $origfilename, $ext, $filetype, $filesize, $isimage, $width, $height, $imagesize, $altsize, $lang);
            } else {
                try {
                    $driver = Storage::getDriver();
                    $location = $driver->uploadGetLocation($tmpName, $origfilename);
                    do_log("location: $location");
                    $url = $driver->getImageUrl($location);
                } catch (\Exception $exception) {
                    do_log('upload failed: ' . $exception->getMessage() . $exception->getTraceAsString(), 'error');
                    $warning = $exception->getMessage();
                }
            }
            if (! $warning) {
                $dlkey = md5($location . microtime(true));
                $this->attachmentService->saveAttachment([
                    'userid' => $curUser['id'],
                    'width' => $width,
                    'filename' => $origfilename,
                    'filetype' => $filetype,
                    'filesize' => $filesize,
                    'location' => $location,
                    'dlkey' => $dlkey,
                    'isimage' => $isimage,
                    'thumb' => $hasthumb,
                    'driver' => $storageDriver,
                ]);
                $countLeft--;
                if (! empty($callbackFunc) && preg_match('/^preview_custom_field_image_\d+$/', $callbackFunc)) {
                    $callbackHtml = sprintf('<script type="text/javascript">parent.%s("%s", "%s")</script>', $callbackFunc, $dlkey, $url);
                } else {
                    $callbackHtml = '<script type="text/javascript">parent.tag_extimage("' . '[attach]' . $dlkey . '[/attach]' . '")</script>';
                }
            } else {
                $callbackHtml = '';
            }
        }

        return [$warning ?? '', $callbackHtml ?? '', $countLeft];
    }

    /**
     * Store an attachment in the local filesystem, applying the legacy
     * thumbnail / watermark pipeline for images.
     *
     * @return array{0: string, 1: string, 2: string, 3: bool, 4: string, 5: int, 6: bool, 7: int}
     *               [warning, location, url, hasthumb, filetype, filesize, isimage, width]
     */
    private function storeLocal(Request $request, $file, string $tmpName, string $origfilename, string $ext, string $filetype, int $filesize, bool $isimage, int $width, int $height, array $imagesize, string $altsize, array $lang): array
    {
        $savedirectory = get_setting('attachment.savedirectory', './attachments');
        $savedirectorytype = get_setting('attachment.savedirectorytype', 'monthdir');
        if ($savedirectorytype == 'onedir') {
            $savepath = '';
        } elseif ($savedirectorytype == 'daydir') {
            $savepath = date('Ymd') . '/';
        } else {
            $savepath = date('Ym') . '/';
        }

        $filemd5 = md5_file($tmpName);
        $filename = date('YmdHis') . $filemd5;
        $file_location = make_folder($savedirectory . '/', $savepath) . $filename;
        do_log("file_location: $file_location");
        $db_file_location = $savepath . $filename;
        $abandonorig = false;
        $hasthumb = false;

        if ($isimage) {
            $maycreatethumb = false;
            $stop = false;
            if ($imagesize) {
                $it = $imagesize[2];
                if ($it != 1 || ! $this->attachmentService->isGifAni($tmpName)) {
                    $thumbnailtype = get_setting('attachment.thumbnailtype', 'createthumb');
                    if ($thumbnailtype != 'no') {
                        if ($altsize == 'yes') {
                            $targetwidth = (int) get_setting('attachment.altthumbwidth', 180);
                            $targetheight = (int) get_setting('attachment.altthumbheight', 135);
                        } else {
                            $targetwidth = (int) get_setting('attachment.thumbwidth', 500);
                            $targetheight = (int) get_setting('attachment.thumbheight', 500);
                        }
                        $hscale = $height / $targetheight;
                        $wscale = $width / $targetwidth;
                        $scale = ($hscale < 1 && $wscale < 1) ? 1 : (($hscale > $wscale) ? $hscale : $wscale);
                        $newwidth = floor($width / $scale);
                        $newheight = floor($height / $scale);
                        if ($scale != 1) {
                            if ($it == 1) {
                                $orig = @imagecreatefromgif($tmpName);
                            } elseif ($it == 2) {
                                $orig = @imagecreatefromjpeg($tmpName);
                            } else {
                                $orig = @imagecreatefrompng($tmpName);
                            }
                            if ($orig && ! $stop) {
                                $thumb = imagecreatetruecolor($newwidth, $newheight);
                                imagecopyresampled($thumb, $orig, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
                                if ($thumbnailtype == 'createthumb') {
                                    $hasthumb = true;
                                    imagejpeg($thumb, $file_location . '.' . $ext . '.thumb.jpg', (int) get_setting('attachment.thumbquality', 80));
                                } elseif ($thumbnailtype == 'resizebigimg') {
                                    $ext = 'jpg';
                                    $filetype = 'image/jpeg';
                                    $it = 2;
                                    $height = $newheight;
                                    $width = $newwidth;
                                    $maycreatethumb = true;
                                    $abandonorig = true;
                                }
                            }
                        }
                    }

                    $watermarkpos = get_setting('attachment.watermarkpos', '9');
                    if ($watermarkpos != 'no' && ! $stop) {
                        if ($width > (int) get_setting('attachment.watermarkwidth', 300) && $height > (int) get_setting('attachment.watermarkheight', 300)) {
                            if ($abandonorig) {
                                $resource = $thumb;
                            } else {
                                $resource = imagecreatetruecolor($width, $height);
                                if ($it == 1) {
                                    $resource_p = @imagecreatefromgif($tmpName);
                                } elseif ($it == 2) {
                                    $resource_p = @imagecreatefromjpeg($tmpName);
                                } else {
                                    $resource_p = @imagecreatefrompng($tmpName);
                                }
                                imagecopy($resource, $resource_p, 0, 0, 0, 0, $width, $height);
                            }
                            $watermark = imagecreatefrompng(public_path('pic/watermark.png'));
                            $watermark_width = imagesx($watermark);
                            $watermark_height = imagesy($watermark);
                            if ($watermarkpos == 'random') {
                                $watermarkpos = mt_rand(1, 9);
                            }
                            switch ($watermarkpos) {
                                case 1:
                                    $wmx = 5;
                                    $wmy = 5;
                                    break;
                                case 2:
                                    $wmx = ($width - $watermark_width) / 2;
                                    $wmy = 5;
                                    break;
                                case 3:
                                    $wmx = $width - $watermark_width - 5;
                                    $wmy = 5;
                                    break;
                                case 4:
                                    $wmx = 5;
                                    $wmy = ($height - $watermark_height) / 2;
                                    break;
                                case 5:
                                    $wmx = ($width - $watermark_width) / 2;
                                    $wmy = ($height - $watermark_height) / 2;
                                    break;
                                case 6:
                                    $wmx = $width - $watermark_width - 5;
                                    $wmy = ($height - $watermark_height) / 2;
                                    break;
                                case 7:
                                    $wmx = 5;
                                    $wmy = $height - $watermark_height - 5;
                                    break;
                                case 8:
                                    $wmx = ($width - $watermark_width) / 2;
                                    $wmy = $height - $watermark_height - 5;
                                    break;
                                case 9:
                                    $wmx = $width - $watermark_width - 5;
                                    $wmy = $height - $watermark_height - 5;
                                    break;
                                default:
                                    $wmx = 5;
                                    $wmy = 5;
                                    break;
                            }
                            imagecopy($resource, $watermark, $wmx, $wmy, 0, 0, $watermark_width, $watermark_height);
                            if ($it == 1) {
                                imagegif($resource, $file_location . '.' . $ext);
                            } elseif ($it == 2) {
                                imagejpeg($resource, $file_location . '.' . $ext, (int) get_setting('attachment.watermarkquality', 85));
                            } else {
                                imagepng($resource, $file_location . '.' . $ext);
                            }
                            $filesize = filesize($file_location . '.' . $ext);
                            $maycreatethumb = false;
                            $abandonorig = true;
                        }
                    }

                    if ($maycreatethumb) {
                        imagejpeg($thumb, $file_location . '.' . $ext, (int) get_setting('attachment.thumbquality', 80));
                        $filesize = filesize($file_location . '.' . $ext);
                    }
                }
            } else {
                return [$lang['text_invalid_image_file'], '', '', false, $filetype, $filesize, $isimage, $width];
            }
        }

        if (! $abandonorig) {
            if (! $file->move(dirname($file_location . '.' . $ext), basename($file_location . '.' . $ext))) {
                return [$lang['text_cannot_move_file'], '', '', $hasthumb, $filetype, $filesize, $isimage, $width];
            }
        }

        $httpdirectory = get_setting('attachment.httpdirectory', 'attachments');
        $url = $httpdirectory . '/' . $db_file_location . '.' . $ext;
        if ($hasthumb) {
            $url .= '.thumb.jpg';
        }
        $location = $db_file_location . '.' . $ext;

        return ['', $location, $url, $hasthumb, $filetype, $filesize, $isimage, $width];
    }

    private function incrementDownloads(Attachment $attachment): void
    {
        Attachment::query()->where('id', $attachment->id)->increment('downloads');
        NexusDB::cache_del('attachment_' . $attachment->dlkey . '_content');
    }

    private function bootstrapGlobals(array $curUser): void
    {
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['enableattach_attachment'] = (string) get_setting('attachment.enableattach', 'no');
    }
}