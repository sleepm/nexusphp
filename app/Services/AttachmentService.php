<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AttachmentService
{
    public function isEnabled(): bool
    {
        return (string) get_setting('attachment.enableattach', 'no') === 'yes';
    }

    public function getUserClass(int $userId): int
    {
        $user = User::query()->find($userId, ['class']);
        return $user ? (int) $user->class : 0;
    }

    public function getCountSoFar(int $userId): int
    {
        $since = date('Y-m-d H:i:s', time() - 86400);
        return Attachment::query()
            ->where('userid', $userId)
            ->where('added', '>', $since)
            ->count();
    }

    public function getCountLimit(int $class): int
    {
        $levels = [
            (int) get_setting('attachment.classfour', 13) => (int) get_setting('attachment.countfour', 500),
            (int) get_setting('attachment.classthree', 5) => (int) get_setting('attachment.countthree', 20),
            (int) get_setting('attachment.classtwo', 2) => (int) get_setting('attachment.counttwo', 10),
            (int) get_setting('attachment.classone', 1) => (int) get_setting('attachment.countone', 5),
        ];
        krsort($levels);
        foreach ($levels as $minClass => $count) {
            if ($class >= $minClass && $count) {
                return $count;
            }
        }
        return 0;
    }

    public function getCountLeft(int $userId): int
    {
        $class = $this->getUserClass($userId);
        $limit = $this->getCountLimit($class);
        $sofar = $this->getCountSoFar($userId);
        return max(0, $limit - $sofar);
    }

    public function getSizeLimitByte(int $class): int
    {
        $levels = [
            (int) get_setting('attachment.classfour', 13) => (int) get_setting('attachment.sizefour', 2048),
            (int) get_setting('attachment.classthree', 5) => (int) get_setting('attachment.sizethree', 1024),
            (int) get_setting('attachment.classtwo', 2) => (int) get_setting('attachment.sizetwo', 512),
            (int) get_setting('attachment.classone', 1) => (int) get_setting('attachment.sizeone', 256),
        ];
        krsort($levels);
        foreach ($levels as $minClass => $sizeKb) {
            if ($class >= $minClass && $sizeKb) {
                return $sizeKb * 1024;
            }
        }
        return 0;
    }

    public function getAllowedExts(int $class): array
    {
        $exts = [];
        $levelExts = [
            (int) get_setting('attachment.classfour', 13) => (string) get_setting('attachment.extfour', 'doc, xls'),
            (int) get_setting('attachment.classthree', 5) => (string) get_setting('attachment.extthree', 'mp3, ogg, oga, flv'),
            (int) get_setting('attachment.classtwo', 2) => (string) get_setting('attachment.exttwo', 'torrent, zip, rar, 7z, gzip, gz'),
            (int) get_setting('attachment.classone', 1) => (string) get_setting('attachment.extone', 'jpg, jpeg, png, gif'),
        ];
        ksort($levelExts);
        foreach ($levelExts as $minClass => $extString) {
            if ($class >= $minClass) {
                foreach (explode(',', $extString) as $e) {
                    $e = trim($e);
                    if ($e !== '') {
                        $exts[] = $e;
                    }
                }
            }
        }
        return array_unique($exts);
    }

    public function getBannedExts(): array
    {
        return ['exe', 'com', 'bat', 'msi'];
    }

    public function isGifAni(string $filename): bool
    {
        if (!($fh = @fopen($filename, 'rb'))) {
            return false;
        }
        $count = 0;
        while (!feof($fh) && $count < 2) {
            $chunk = fread($fh, 1024 * 100);
            $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00\x2C#s', $chunk, $matches);
        }
        fclose($fh);
        return $count > 1;
    }

    public function getStorageDriver(): string
    {
        return (string) get_setting('image_hosting.driver', 'local');
    }

    public function saveAttachment(array $data): Attachment
    {
        $attachment = new Attachment();
        $attachment->userid = $data['userid'];
        $attachment->width = $data['width'] ?? 0;
        $attachment->added = $data['added'] ?? date('Y-m-d H:i:s');
        $attachment->filename = $data['filename'];
        $attachment->filetype = $data['filetype'];
        $attachment->filesize = $data['filesize'];
        $attachment->location = $data['location'];
        $attachment->dlkey = $data['dlkey'];
        $attachment->isimage = $data['isimage'] ? 1 : 0;
        $attachment->thumb = $data['thumb'] ? 1 : 0;
        $attachment->driver = $data['driver'] ?? 'local';
        $attachment->save();
        return $attachment;
    }
}