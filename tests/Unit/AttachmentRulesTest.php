<?php

namespace Tests\Unit;

use App\Services\Inbox\AttachmentRules;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class AttachmentRulesTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        $this->temporary[] = $path;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function office(string $name, string $member): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'off');
        $this->temporary[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString($member, '<x/>');
        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return array<string, string> conteúdo mínimo reconhecido pelo libmagic */
    private static function samples(): array
    {
        return [
            'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", 64),
            'png' => "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89".str_repeat("\x00", 32),
            'pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
            'mp3' => "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x00", 64),
            'mp4' => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free".str_repeat("\x00", 64),
            'ole' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 600),
            'exe' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 128),
        ];
    }

    /** @return array<string, array{string, string, string}> nome, amostra, tipo esperado */
    public static function aceitos(): array
    {
        return [
            'jpeg' => ['foto.jpg', 'jpeg', 'image/jpeg'],
            'jpeg maiusculo' => ['FOTO.JPEG', 'jpeg', 'image/jpeg'],
            'png' => ['print.png', 'png', 'image/png'],
            'pdf' => ['orcamento.pdf', 'pdf', 'application/pdf'],
            'mp3' => ['audio.mp3', 'mp3', 'audio/mpeg'],
            'mp4' => ['video.mp4', 'mp4', 'video/mp4'],
            'm4a vira audio' => ['voz.m4a', 'mp4', 'audio/mp4'],
            'doc' => ['contrato.doc', 'ole', 'application/msword'],
            'xls' => ['planilha.xls', 'ole', 'application/vnd.ms-excel'],
        ];
    }

    #[DataProvider('aceitos')]
    public function test_aceita_pelo_conteudo_e_pela_extensao_coerente(string $name, string $sample, string $mime): void
    {
        $info = AttachmentRules::inspect($this->file($name, self::samples()[$sample]));

        $this->assertIsArray($info);
        $this->assertSame($mime, $info['mime']);
        $this->assertNull(AttachmentRules::validate($this->file($name, self::samples()[$sample])));
    }

    public function test_docx_xlsx_e_csv(): void
    {
        $docx = AttachmentRules::inspect($this->office('proposta.docx', 'word/document.xml'));
        $xlsx = AttachmentRules::inspect($this->office('tabela.xlsx', 'xl/workbook.xml'));
        $csv = AttachmentRules::inspect($this->file('lista.csv', "nome;valor\nA;10\n"));

        $this->assertIsArray($docx);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx['mime']);
        $this->assertIsArray($xlsx);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx['mime']);
        $this->assertIsArray($csv);
        $this->assertSame('text/csv', $csv['mime']);
    }

    /** @return array<string, array{string, string}> */
    public static function recusados(): array
    {
        return [
            'executavel' => ['programa.exe', 'exe'],
            'executavel renomeado para pdf' => ['orcamento.pdf', 'exe'],
            'pdf com extensao exe' => ['orcamento.exe', 'pdf'],
            'png com extensao jpg' => ['foto.jpg', 'png'],
            'sem extensao' => ['orcamento', 'pdf'],
        ];
    }

    #[DataProvider('recusados')]
    public function test_recusa_quando_o_conteudo_nao_combina_com_o_nome(string $name, string $sample): void
    {
        $this->assertNotNull(AttachmentRules::validate($this->file($name, self::samples()[$sample])));
    }

    public function test_texto_puro_so_vale_como_csv_e_scripts_e_zip_sao_recusados(): void
    {
        $this->assertNotNull(AttachmentRules::validate($this->file('script.js', 'alert(1);')));
        $this->assertNotNull(AttachmentRules::validate($this->file('notas.txt', 'ola')));
        $this->assertNotNull(AttachmentRules::validate($this->office('pacote.zip', 'a.txt')));
        $this->assertNotNull(AttachmentRules::validate($this->file('instalar.bat', "@echo off\r\n")));
    }

    public function test_recusa_arquivo_vazio_e_acima_de_16_mb(): void
    {
        $this->assertStringContainsString('vazio', (string) AttachmentRules::validate($this->file('vazio.pdf', '')));

        $big = $this->file('grande.pdf', self::samples()['pdf'].str_repeat('a', AttachmentRules::MAX_BYTES));

        $this->assertStringContainsString('16 MB', (string) AttachmentRules::validate($big));
    }

    public function test_gravacao_do_microfone_aceita_webm_so_no_modo_voz(): void
    {
        $webm = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02".str_repeat("\x00", 64);

        $this->assertNull(AttachmentRules::validate($this->file('audio.webm', $webm), voice: true));
        $this->assertNotNull(AttachmentRules::validate($this->file('audio.webm', $webm)));
        $this->assertSame('audio', AttachmentRules::inspect($this->file('audio.webm', $webm), voice: true)['kind'] ?? null);
    }

    public function test_nome_e_saneado_sem_caminho_nem_controle(): void
    {
        $this->assertSame('passwd', AttachmentRules::safeName('../../etc/passwd'));
        $this->assertSame('arquivo.pdf', AttachmentRules::safeName("arquivo\x00\x1f.pdf"));
        $this->assertSame('arquivo', AttachmentRules::safeName('..'));
        $this->assertSame(100, mb_strlen(AttachmentRules::safeName(str_repeat('a', 300).'.pdf')));
    }

    public function test_tipo_pela_extensao_do_mime_gravado(): void
    {
        $this->assertSame('image', AttachmentRules::kindOfStored('image/png'));
        $this->assertSame('audio', AttachmentRules::kindOfStored('audio/webm'));
        $this->assertSame('video', AttachmentRules::kindOfStored('video/mp4'));
        $this->assertSame('document', AttachmentRules::kindOfStored('application/pdf'));
    }
}
