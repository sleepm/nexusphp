<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Support\Facades\Auth;

class FormatsController extends Controller
{
    /**
     * Downloaded-files formats help page. Mirrors legacy public/formats.php:
     * a static guide about compression archives, multimedia files, CD images
     * and other common file types. Requires login (legacy loggedinorreturn()).
     */
    public function web()
    {
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $GLOBALS['CURUSER'] = $currentUser->toArray();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $content = $this->capture(function () {
            $this->openSection();
            echo '<h2>A Handy Guide to Using the Files You\'ve Downloaded</h2>' . "\n";
            $this->openBody();
            echo '<p>Hey guys, here\'s some info about common files that you can download from the internet,'
                . ' and a little bit about using these files for their intended purposes. If you\'re stuck'
                . ' on what exactly a file is or how to open it maybe your answer lies ahead. If you dont\''
                . ' find your answer here, then please post in the "Forum". So without further adieu lets'
                . ' get the show on the road!</p>' . "\n";
            $this->closeBody();

            $this->openSection();
            echo '<h2>Compression Files</h2>' . "\n";
            $this->openBody();
            echo '<p><b>.rar .zip .ace .r01 .001</b></p>' . "\n"
                . '<p>These extensions are quite common and mean that your file(s) are compressed into an "archive".'
                . ' This is just a way of making the files more compact and easier to download.</p>' . "\n"
                . '<p>To open any of those archives listed above you can use <a href="http://www.rarsoft.com/download.htm">WinRAR</a>'
                . ' (Make sure you have the latest version) or <a href="http://www.powerarchiver.com/download/">PowerArchiver</a>.</p>' . "\n"
                . '<p>If those progams aren\'t working for you and you have a .zip file you can try'
                . ' <a href="http://www.winzip.com/download.htm">WinZip</a> (Trial version).</p>' . "\n"
                . '<p>If the two first mentioned programs aren\'t working for you and you have a .ace or .001'
                . ' file you can try <a href="http://www.winace.com/">Winace</a> (Trial version).</p>' . "\n\n"
                . '<p><b>.cbr .cbz</b></p>' . "\n"
                . '<p>These are usually comic books in an archive format. a .cbr file is actually the same'
                . ' thing as a .rar file and a .cbz file is the same as a .zip file. However, often when'
                . ' opening them with WinRAR or WinZip it will disorder your pages. To display these'
                . ' archives properly it\'s often best to use <a href="http://www.geocities.com/davidayton/CDisplay">'
                . 'CDisplay</a>.</p>' . "\n";
            $this->closeBody();

            $this->openSection();
            echo '<h2>Multimedia Files</h2>' . "\n";
            $this->openBody();
            echo '<p><b>.avi .mpg. .mpeg .divx .xvid .wmv</b></p>' . "\n"
                . '<p>These files are usually movies or TVshows, or a host of other types of media. They can'
                . ' be viewed using various media players, but I suggest using'
                . ' <a href="http://www.inmatrix.com/files/zoomplayer_download.shtml">Zoomplayer</a>,'
                . ' <a href="http://www.bsplayer.org/">BSPlayer</a>, <a href="http://www.videolan.org/vlc/">VLC media player</a>'
                . ' or <a href="http://www.microsoft.com/windows/'
                . 'windowsmedia/default.aspx">Windows Media Player</a>. Also, you\'ll need to make sure you have'
                . ' the right codecs to play each individual file. Codecs are a tricky business sometimes so to help'
                . ' you out with your file and what exact codecs it needs try using <a href="http://www.headbands.com/'
                . 'gspot/download.html">GSpot</a>. It tells you what codecs you need. Then just look on the net to find'
                . ' them, below are some common codecs and their download links for quick reference:</p>' . "\n\n"
                . '<a href="http://sourceforge.net/project/showfiles.php?group_id=53761&release_id=95213">ffdshow</a>'
                . ' (Recommended! (plays many formats: XviD, DivX, 3ivX, mpeg-4))<br />' . "\n"
                . '<a href="http://nic.dnsalias.com/xvid.html">XviD codec</a><br />' . "\n"
                . '<a href="http://www.divx.com/divx/">DivX codec</a><br />' . "\n"
                . '<a href="http://sourceforge.net/project/showfiles.php?group_id=66022&release_id=178906">ac3filter</a>'
                . ' (for AC3 soundtracks, aka "5.1")<br />' . "\n"
                . '<a href="http://tobias.everwicked.com/oggds.htm">Ogg media codec</a> (for .OGM files)<br />' . "\n\n"
                . '<p>Can\'t find what you\'re looking for? Check out these sites...</p>' . "\n\n"
                . '<a href="http://www.divx-digest.com/">DivX-Digest</a><br />' . "\n"
                . '<a href="http://www.digital-digest.com/">Digital-Digest</a><br />' . "\n"
                . '<a href="http://www.doom9.org/">Doom9</a><br />' . "\n"
                . '<a href="http://www.dvdrhelp.com/">DVD-R Help</a><br />' . "\n\n\n"
                . '<p><b>.mov</b></p>' . "\n"
                . '<p>These are <a href="http://www.apple.com/quicktime/">QuickTime</a> files. Hopefully you'
                . ' won\'t have to open these as I hate quicktime, but if you do you can'
                . ' <a href="http://www.apple.com/quicktime/download/">get it here</a>.'
                . ' There are however alternatives to the original program,'
                . ' Check out <a href="http://home.hccnet.nl/h.edskes/finalbuilds.htm">QuickTime Alternative</a>.</p>' . "\n\n\n"
                . '<p><b>.ra .rm .ram</b></p>' . "\n"
                . '<p>These are <a href="http://www.real.com">RealPlayer</a> files. RealPlayer IMO is the'
                . ' devils work. It installs lord knows what on your system and never really goes away when'
                . ' you want to uninstall it. Still if you insists you can get the player'
                . ' <a href="http://service.real.com/downloads.html">here</a>.'
                . ' There are however alternatives to the original program,'
                . ' check out <a href="http://home.hccnet.nl/h.edskes/finalbuilds.htm">Real Alternative</a>.</p>' . "\n\n\n"
                . '<p><b>vcd/svcd</b></p>' . "\n"
                . '<p>These can be a pain on some peoples setups, but more so, on your stand-alone DVD player.'
                . ' For all your vcd needs check out <a href="http://www.dvdrhelp.com">www.dvdrhelp.com</a>.'
                . ' These guys know their stuff, and can help you with all kinds of media related questions.</p>' . "\n\n\n"
                . '<p><b>.mp3 .mp2</b></p>' . "\n"
                . '<p>Usually music files. Play them with <a href="http://www.winamp.com/">WinAmp</a>.</p>' . "\n\n\n"
                . '<p><b>.ogm .ogg</b></p>' . "\n"
                . '<p>Ogg Vorbis media files. You can find out more about them and download applications'
                . ' <a href="http://www.vorbis.com/download.psp">here</a>.'
                . ' This filetype is another music file format, but can be used for various media. You will'
                . ' probably want to download the <a href="http://tobias.everwicked.com/oggds.htm">'
                . 'DirectShow Ogg filter</a> to play back OGM files. Any new version of'
                . ' <a href="http://www.winamp.com">WinAmp</a> will also do.</p>' . "\n";
            $this->closeBody();

            $this->openSection();
            echo '<h2>CD Image Files</h2>' . "\n";
            $this->openBody();
            echo '<p><b>.bin and .cue</b></p>' . "\n"
                . '<p>These are your standard images of a CD, and are used quite alot these days. To open them'
                . ' you have a couple options. You can burn them using <a href="http://www.ahead.de">Nero</a>'
                . ' (Trial Version) or <a href="http://www.alcohol-software.com/">Alcohol 120%</a>,'
                . ' but this proves to be soooooooo problematic for a lot of people. You should also consult'
                . ' this tutorial for burning images with various software programs You can also use'
                . ' <a href="http://www.daemon-tools.cc/portal/portal.php">Daemon Tools</a>, which lets you'
                . ' mount the image to a "virtual cd-rom", so basically it tricks your computer into thinking'
                . ' that you have another cd-rom and that you\'re putting a cd with your image file on it into'
                . ' this virtual cd-rom, it\'s great cuz you\'ll never make a bad cd again, Alcohol 120% also'
                . ' sports a virtual cd-rom feature. Finally, if you\'re still struggling to access the files'
                . ' contained within any given image file you can use <a href="http://cdmage.cjb.net/">CDMage</a>'
                . ' to extract the files and then burn them, or just access them from your hard drive. You can'
                . ' also use <a href="http://www.vcdgear.com/">VCDGear</a> to extract the mpeg contents of a'
                . ' SVCD or VCD image file such as bin/cue.</p>' . "\n\n\n"
                . '<p><b>.iso</b></p>' . "\n"
                . '<p>Another type of image file that follows similar rules as .bin and .cue, only you extract'
                . ' or create them using <a href="http://www.winiso.com">WinISO</a> or'
                . ' <a href="http://ww.smart-projects.net/isobuster/">ISOBuster.</a> Sometimes converting a'
                . ' problematic .bin and .cue file to an .iso can help you burn it to a cd.</p>' . "\n\n\n"
                . '<p><b>.ccd .img .sub</b></p>' . "\n"
                . '<p>All these files go together and are in the <a href="http://www.elby.ch/english/products/'
                . 'clone_cd/index.html"> CloneCD</a> format. CloneCD is like most other CD-Burning programs,'
                . ' see the .bin and .cue section if you\'re having problems with these files.</p>' . "\n";
            $this->closeBody();

            $this->openSection();
            echo '<h2>Other Files</h2>' . "\n";
            $this->openBody();
            echo '<p><b>.txt .doc</b></p>' . "\n"
                . '<p>These are text files. .txt files can be opened with notepad or watever you default text'
                . ' editor happens to be, and .doc are opened with Microsoft Word.</p>' . "\n\n\n"
                . '<p><b>.nfo</b></p>' . "\n"
                . '<p>These contain information about the file you just downloaded, and it\'s HIGHLY recommended'
                . ' that you read these! They are plain text files, often with ascii-art. You can open them'
                . ' with Notepad, Wordpad, <a href="http://www.damn.to/software/nfoviewer.html">DAMN NFO Viewer</a>'
                . ' or <a href="http://www.ultraedit.com/">UltraEdit</a>.</p>' . "\n\n\n"
                . '<p><b>.pdf</b></p>' . "\n"
                . '<p>Opened with <a href="http://www.adobe.com/products/acrobat/main.html">Adobe Acrobat Reader</a>.</p>' . "\n\n\n"
                . '<p><b>.jpg .gif .tga .psd</b></p>' . "\n"
                . '<p>Basic image files. These files generally contain pictures, and can be opened with Adobe'
                . ' Photoshop or whatever your default image viewer is.</p>' . "\n\n\n"
                . '<p><b>.sfv</b></p>' . "\n"
                . '<p>Checks to make sure that your multi-volume archives are complete. This just lets you know'
                . ' if you\'ve downloaded something complete or not. (This is not really an issue when DL:ing'
                . ' via torrent.) You can open/activate these files with <a href="http://www.traction-software.co.uk/SFVChecker/">'
                . 'SFVChecker</a> (Trial version) or <a href="http://www.big-o-software.com/products/hksfv/">hkSFV</a> for example.</p>' . "\n\n\n"
                . '<p><b>.par</b></p>' . "\n"
                . '<p>This is a parity file, and is often used when downloading from newsgroups. These files can'
                . ' fill in gaps when you\'re downloading a multi-volume archive and get corrupted or missing parts.'
                . ' Open them with <a href="http://www.pbclements.co.uk/QuickPar/">QuickPar</a>.</p>' . "\n";
            $this->closeBody();

            $this->openSection();
            $this->openBody();
            echo '<p>If you have any suggestion/changes <a href="staff.php"><b>PM</b></a> one of the Admins/SysOp!</p>' . "\n"
                . '<p>This file was originally written by hussdiesel at filesoup, then edited by Rhomboid and re-edited by us.</p>' . "\n";
            $this->closeBody();
        });

        return view('formats', compact('content') + [
            'pageTitle' => 'Downloaded Files',
        ]);
    }

    /**
     * Video formats / release-types help page. Mirrors legacy public/videoformats.php:
     * an explanation of CAM/TS/TC/SCR/DVDRip and the various scene tags. Requires login.
     */
    public function video()
    {
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $GLOBALS['CURUSER'] = $currentUser->toArray();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $content = $this->capture(function () {
            echo '<table class="main" width="940" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">' . "\n";
            echo '<h2>Downloaded a movie and don\'t know what CAM/TS/TC/SCR means?</h2>' . "\n";
            $this->openBody();
            echo '<p><b>CAM -</b></p>' . "\n"
                . '<p>A cam is a theater rip usually done with a digital video camera. A mini tripod is'
                . ' sometimes used, but a lot of the time this wont be possible, so the camera make shake.'
                . ' Also seating placement isn\'t always idle, and it might be filmed from an angle.'
                . ' If cropped properly, this is hard to tell unless there\'s text on the screen, but a lot'
                . ' of times these are left with triangular borders on the top and bottom of the screen.'
                . ' Sound is taken from the onboard microphone of the camera, and especially in comedies,'
                . ' laughter can often be heard during the film. Due to these factors picture and sound'
                . ' quality are usually quite poor, but sometimes we\'re lucky, and the theater will be\''
                . ' fairly empty and a fairly clear signal will be heard.</p>' . "\n\n\n"
                . '<p><b>TELESYNC (TS) -</b></p>' . "\n"
                . '<p>A telesync is the same spec as a CAM except it uses an external audio source (most'
                . ' likely an audio jack in the chair for hard of hearing people). A direct audio source'
                . ' does not ensure a good quality audio source, as a lot of background noise can interfere.'
                . ' A lot of the times a telesync is filmed in an empty cinema or from the projection booth'
                . ' with a professional camera, giving a better picture quality. Quality ranges drastically,'
                . ' check the sample before downloading the full release. A high percentage of Telesyncs'
                . ' are CAMs that have been mislabeled.</p>' . "\n\n\n"
                . '<p><b>TELECINE (TC) -</b></p>' . "\n"
                . '<p>A telecine machine copies the film digitally from the reels. Sound and picture should'
                . ' be very good, but due to the equipment involved and cost telecines are fairly uncommon.'
                . ' Generally the film will be in correct aspect ratio, although 4:3 telecines have existed.'
                . ' A great example is the JURASSIC PARK 3 TC done last year. TC should not be confused with'
                . ' TimeCode , which is a visible counter on screen throughout the film.</p>' . "\n\n\n"
                . '<p><b>SCREENER (SCR) -</b></p>' . "\n"
                . '<p>A pre VHS tape, sent to rental stores, and various other places for promotional use.'
                . ' A screener is supplied on a VHS tape, and is usually in a 4:3 (full screen) a/r, although'
                . ' letterboxed screeners are sometimes found. The main draw back is a "ticker" (a message'
                . ' that scrolls past at the bottom of the screen, with the copyright and anti-copy'
                . ' telephone number). Also, if the tape contains any serial numbers, or any other markings'
                . ' that could lead to the source of the tape, these will have to be blocked, usually with a'
                . ' black mark over the section. This is sometimes only for a few seconds, but unfortunately'
                . ' on some copies this will last for the entire film, and some can be quite big. Depending'
                . ' on the equipment used, screener quality can range from excellent if done from a MASTER'
                . ' copy, to very poor if done on an old VHS recorder thru poor capture equipment on a copied'
                . ' tape. Most screeners are transferred to VCD, but a few attempts at SVCD have occurred,'
                . ' some looking better than others.</p>' . "\n\n\n"
                . '<p><b>DVD-SCREENER (DVDscr) -</b></p>' . "\n"
                . '<p>Same premise as a screener, but transferred off a DVD. Usually letterbox , but without'
                . ' the extras that a DVD retail would contain. The ticker is not usually in the black bars,'
                . ' and will disrupt the viewing. If the ripper has any skill, a DVDscr should be very good.'
                . ' Usually transferred to SVCD or DivX/XviD.</p>' . "\n\n\n"
                . '<p><b>DVDRip -</b></p>' . "\n"
                . '<p>A copy of the final released DVD. If possible this is released PRE retail (for example,'
                . ' Star Wars episode 2) again, should be excellent quality. DVDrips are released in SVCD'
                . ' and DivX/XviD.</p>' . "\n\n\n"
                . '<p><b>VHSRip -</b></p>' . "\n"
                . '<p>Transferred off a retail VHS, mainly skating/sports videos and XXX releases.</p>' . "\n\n\n"
                . '<p><b>TVRip -</b></p>' . "\n"
                . '<p>TV episode that is either from Network (capped using digital cable/satellite boxes are'
                . ' preferable) or PRE-AIR from satellite feeds sending the program around to networks a few'
                . ' days earlier (do not contain "dogs" but sometimes have flickers etc) Some programs such'
                . ' as WWF Raw Is War contain extra parts, and the "dark matches" and camera/commentary'
                . ' tests are included on the rips. PDTV is capped from a digital TV PCI card, generally'
                . ' giving the best results, and groups tend to release in SVCD for these. VCD/SVCD/DivX/XviD'
                . ' rips are all supported by the TV scene.</p>' . "\n\n\n"
                . '<p><b>WORKPRINT (WP) -</b></p>' . "\n"
                . '<p>A workprint is a copy of the film that has not been finished. It can be missing scenes,'
                . ' music, and quality can range from excellent to very poor. Some WPs are very different'
                . ' from the final print (Men In Black is missing all the aliens, and has actors in their'
                . ' places) and others can contain extra scenes (Jay and Silent Bob) . WPs can be nice'
                . ' additions to the collection once a good quality final has been obtained.</p>' . "\n\n\n"
                . '<p><b>DivX Re-Enc -</b></p>' . "\n"
                . '<p>A DivX re-enc is a film that has been taken from its original VCD source, and re-encoded'
                . ' into a small DivX file. Most commonly found on file sharers, these are usually labeled'
                . ' something like Film.Name.Group(1of2) etc. Common groups are SMR and TND. These aren\'t'
                . ' really worth downloading, unless you\'re that unsure about a film u only want a 200mb copy'
                . ' of it. Generally avoid.</p>' . "\n\n\n"
                . '<p><b>Watermarks -</b></p>' . "\n"
                . '<p>A lot of films come from Asian Silvers/PDVD (see below) and these are tagged by the'
                . ' people responsible. Usually with a letter/initials or a little logo, generally in one'
                . ' of the corners. Most famous are the "Z" "A" and "Globe" watermarks.</p>' . "\n\n\n"
                . '<p><b>Asian Silvers / PDVD -</b></p>' . "\n"
                . '<p>These are films put out by eastern bootleggers, and these are usually bought by some'
                . ' groups to put out as their own. Silvers are very cheap and easily available in a lot of'
                . ' countries, and its easy to put out a release, which is why there are so many in the scene'
                . ' at the moment, mainly from smaller groups who don\'t last more than a few releases. PDVDs'
                . ' are the same thing pressed onto a DVD. They have removable subtitles, and the quality is'
                . ' usually better than the silvers. These are ripped like a normal DVD, but usually released'
                . ' as VCD.</p>' . "\n\n\n"
                . '<p><b>Scene Tags...</b></p>' . "\n\n"
                . '<p><b>PROPER -</b></p>' . "\n"
                . '<p>Due to scene rules, whoever releases the first Telesync has won that race (for example).'
                . ' But if the quality of that release is fairly poor, if another group has another telesync'
                . ' (or the same source in higher quality) then the tag PROPER is added to the folder to'
                . ' avoid being duped. PROPER is the most subjective tag in the scene, and a lot of people'
                . ' will generally argue whether the PROPER is better than the original release. A lot of'
                . ' groups release PROPERS just out of desperation due to losing the race. A reason for the'
                . ' PROPER should always be included in the NFO.</p>' . "\n\n\n"
                . '<p><b>LIMITED -</b></p>' . "\n"
                . '<p>A limited movie means it has had a limited theater run, generally opening in less than'
                . ' 250 theaters, generally smaller films (such as art house films) are released as limited.</p>' . "\n\n\n"
                . '<p><b>INTERNAL -</b></p>' . "\n"
                . '<p>An internal release is done for several reasons. Classic DVD groups do a lot of INTERNAL'
                . ' releases, as they wont be dupe\'d on it. Also lower quality theater rips are done INTERNAL'
                . ' so not to lower the reputation of the group, or due to the amount of rips done already.'
                . ' An INTERNAL release is available as normal on the groups affiliate sites, but they can\'t'
                . ' be traded to other sites without request from the site ops. Some INTERNAL releases still'
                . ' trickle down to IRC/Newsgroups, it usually depends on the title and the popularity.'
                . ' Earlier in the year people referred to Centropy going "internal". This meant the group'
                . ' were only releasing the movies to their members and site ops. This is in a different'
                . ' context to the usual definition.</p>' . "\n\n\n"
                . '<p><b>STV -</b></p>' . "\n"
                . '<p>Straight To Video. Was never released in theaters, and therefore a lot of sites do not'
                . ' allow these.</p>' . "\n\n\n"
                . '<p><b>ASPECT RATIO TAGS -</b></p>' . "\n"
                . '<p>These are *WS* for widescreen (letterbox) and *FS* for Fullscreen.</p>' . "\n\n\n"
                . '<p><b>REPACK -</b></p>' . "\n"
                . '<p>If a group releases a bad rip, they will release a Repack which will fix the problems.</p>' . "\n\n\n"
                . '<p><b>NUKED -</b></p>' . "\n"
                . '<p>A film can be nuked for various reasons. Individual sites will nuke for breaking their'
                . ' rules (such as "No Telesyncs") but if the film has something extremely wrong with it'
                . ' (no soundtrack for 20mins, CD2 is incorrect film/game etc) then a global nuke will occur,'
                . ' and people trading it across sites will lose their credits. Nuked films can still reach'
                . ' other sources such as p2p/usenet, but its a good idea to check why it was nuked first in'
                . ' case. If a group realise there is something wrong, they can request a nuke.</p>' . "\n\n\n"
                . '<p><b>NUKE REASONS...</b></p>' . "\n"
                . '<p>this is a list of common reasons a film can be nuked for (generally DVDRip)</p>' . "\n\n"
                . '<p><b>BAD A/R</b> = bad aspect ratio, ie people appear too fat/thin<br />' . "\n"
                . '<b>BAD IVTC</b> = bad inverse telecine. process of converting framerates was incorrect.<br />' . "\n"
                . '<b>INTERLACED</b> = black lines on movement as the field order is incorrect.</p>' . "\n\n\n"
                . '<p><b>DUPE -</b></p>' . "\n"
                . '<p>Dupe is quite simply, if something exists already, then theres no reason for it to exist'
                . ' again without proper reason.</p>' . "\n";
            $this->closeBody();
            echo '</td></tr></table>' . "\n";
        });

        return view('videoformats', compact('content') + [
            'pageTitle' => 'Video Formats',
        ]);
    }

    private function openSection(): void
    {
        echo '<table class="main" width="737" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">' . "\n";
    }

    private function openBody(): void
    {
        echo '<table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">' . "\n";
    }

    private function closeBody(): void
    {
        echo '</td></tr></table>' . "\n"
            . '</td></tr></table>' . "\n"
            . '<br />' . "\n";
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
