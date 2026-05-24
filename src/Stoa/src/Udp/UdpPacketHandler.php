<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Udp;

interface UdpPacketHandler
{
    public function __invoke(UdpSession $session): void;
}
