<?php

namespace Tests\Unit;

use App\Services\Inbox\OggOpus;
use PHPUnit\Framework\TestCase;

class OggOpusTest extends TestCase
{
    /** O começo de um OGG/Opus: página `OggS` de 27 bytes + 1 segmento, e o `OpusHead` no byte 28. */
    public static function header(int $channels = 1, string $magic = 'OpusHead'): string
    {
        return 'OggS'.chr(0).chr(2).str_repeat("\0", 8).str_repeat("\0", 4).str_repeat("\0", 4).str_repeat("\0", 4).chr(1).chr(19)
            .$magic.chr(1).chr($channels).chr(0x38).chr(0x01).pack('V', 48000).pack('v', 0).chr(0);
    }

    public function test_ogg_opus_mono_e_aceito(): void
    {
        $this->assertTrue(OggOpus::isMono(self::header()));
        $this->assertSame(28, strpos(self::header(), 'OpusHead'));
    }

    public function test_estereo_e_recusado(): void
    {
        $this->assertFalse(OggOpus::isMono(self::header(2)));
    }

    public function test_ogg_vorbis_e_recusado(): void
    {
        $this->assertFalse(OggOpus::isMono(self::header(1, "\x01vorbis ")));
    }

    public function test_arquivo_curto_ou_truncado_e_recusado(): void
    {
        $this->assertFalse(OggOpus::isMono(''));
        $this->assertFalse(OggOpus::isMono('OggS'));
        $this->assertFalse(OggOpus::isMono(substr(self::header(), 0, 37)));
    }

    public function test_mp3_e_webm_sao_recusados(): void
    {
        $this->assertFalse(OggOpus::isMono("ID3\x04\x00\x00\x00\x00\x00#TSSE".str_repeat("\0", 60)));
        $this->assertFalse(OggOpus::isMono("\x1A\x45\xDF\xA3".str_repeat("\0", 60)));
    }
}
