<?php

namespace Tests\Unit;

use App\Support\MediaPresentation;
use PHPUnit\Framework\TestCase;

/** Como a mídia aparece: o tipo, a etiqueta do ícone e o tamanho legível. */
class MediaPresentationTest extends TestCase
{
    public function test_tipo_por_mime(): void
    {
        $this->assertSame('sticker', MediaPresentation::kind('image/webp'));
        $this->assertSame('image', MediaPresentation::kind('image/jpeg'));
        $this->assertSame('video', MediaPresentation::kind('video/mp4'));
        $this->assertSame('audio', MediaPresentation::kind('audio/ogg'));
        $this->assertSame('document', MediaPresentation::kind('application/pdf'));
        $this->assertSame('document', MediaPresentation::kind(null));
    }

    public function test_extensao_pelo_nome_e_depois_pelo_mime(): void
    {
        $this->assertSame('DOCX', MediaPresentation::extension('COTAÇÃO DIMETAL.docx', null));
        $this->assertSame('XLSX', MediaPresentation::extension('planilha.final.xlsx', 'application/octet-stream'));
        $this->assertSame('PDF', MediaPresentation::extension(null, 'application/pdf'));
        $this->assertSame('PDF', MediaPresentation::extension('sem-extensao', 'application/pdf'));
        $this->assertSame('', MediaPresentation::extension('sem-extensao', null));
        $this->assertSame('', MediaPresentation::extension(null, null));
    }

    public function test_depois_do_ultimo_ponto_so_vale_extensao_de_verdade(): void
    {
        $this->assertSame('', MediaPresentation::extension('Relatório v2.1 final', null));
        $this->assertSame('PDF', MediaPresentation::extension('Relatório v2.1 final', 'application/pdf'));
        $this->assertSame('', MediaPresentation::extension('nota.', null));
        $this->assertSame('', MediaPresentation::extension('a.extensaomuitolonga', null));
        $this->assertSame('GZ', MediaPresentation::extension('backup.tar.gz', null));
    }

    public function test_etiqueta_do_icone_por_extensao(): void
    {
        $this->assertSame(['label' => 'W', 'tone' => 'blue'], MediaPresentation::badge('DOCX'));
        $this->assertSame(['label' => 'W', 'tone' => 'blue'], MediaPresentation::badge('DOC'));
        $this->assertSame(['label' => 'X', 'tone' => 'green'], MediaPresentation::badge('XLSX'));
        $this->assertSame(['label' => 'X', 'tone' => 'green'], MediaPresentation::badge('CSV'));
        $this->assertSame(['label' => 'P', 'tone' => 'orange'], MediaPresentation::badge('PPTX'));
        $this->assertSame(['label' => 'PDF', 'tone' => 'red'], MediaPresentation::badge('PDF'));
        $this->assertSame(['label' => '', 'tone' => 'gray'], MediaPresentation::badge('ZIP'));
        $this->assertSame(['label' => '', 'tone' => 'gray'], MediaPresentation::badge(''));
    }

    public function test_tamanho_legivel_e_nulo_sem_valor(): void
    {
        $this->assertSame('22 KB', MediaPresentation::size(22528));
        $this->assertSame('512 B', MediaPresentation::size(512));
        $this->assertSame('1 KB', MediaPresentation::size(1024));
        $this->assertSame('1,5 MB', MediaPresentation::size(1572864));
        $this->assertSame('2 MB', MediaPresentation::size(2097152));
        $this->assertSame('1,2 GB', MediaPresentation::size(1288490189));
        $this->assertNull(MediaPresentation::size(0));
        $this->assertNull(MediaPresentation::size(null));
    }
}
