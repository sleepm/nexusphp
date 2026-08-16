<?php

namespace App\Exceptions;

class TorrentExistedException extends NexusException
{
    private int $torrentId;

    public function __construct(int $torrentId)
    {
        parent::__construct(nexus_trans('upload.torrent_existed', ['id' => $torrentId]));
        $this->torrentId = $torrentId;
    }

    public function getTorrentId(): int
    {
        return $this->torrentId;
    }
}