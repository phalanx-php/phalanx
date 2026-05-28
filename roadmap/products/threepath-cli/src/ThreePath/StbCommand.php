<?php

declare(strict_types=1);

namespace ThreePath;

final readonly class StbCommand
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public array $payload = [],
    ) {}

    public static function helloDiscovery(): self
    {
        return new self('HELLO_DISCOVERY', 'Ping the STB for device info');
    }

    public static function channelUp(): self
    {
        return new self('CH_UP', 'Channel up');
    }

    public static function channelDown(): self
    {
        return new self('CH_DOWN', 'Channel down');
    }

    public static function forceChannelSwitch(int $serviceId): self
    {
        return new self('FORCE_CH_SWITCH', 'Switch to channel', ['service_id' => $serviceId]);
    }

    public static function tunerStatus(): self
    {
        return new self('CMD_GET_TUNER_STATUS', 'Get DVB-S tuner status');
    }

    public static function currentEpg(int $serviceId): self
    {
        return new self('CMD_GET_CURRENT_EPG', 'Get current EPG', ['service_id' => $serviceId]);
    }

    public static function sendButtonKey(string $key): self
    {
        return new self('CMD_SEND_BUTTON_KEY', 'Send remote key press', ['button_key' => $key]);
    }

    public static function reboot(): self
    {
        return new self('CMD_REBOOT_STB', 'Reboot the STB');
    }

    public static function recordingList(): self
    {
        return new self('CMD_GET_SHORT_RECORDING_LIST', 'Get short recording list');
    }
}
