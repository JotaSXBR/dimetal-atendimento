<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** E-mail e IP novos a cada teste: com o cache em Redis (CI) as chaves do limitador passam de um teste para o outro. */
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->email = 'limite-'.bin2hex(random_bytes(6)).'@dimetal.test';
    }

    private function attempt(string $email, ?string $ip = null): int
    {
        $ip ??= '10.'.random_int(1, 250).'.'.random_int(1, 250).'.'.random_int(1, 250);

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', ['email' => $email, 'password' => 'senha-errada'])->getStatusCode();
    }

    public function test_o_teto_por_hora_vale_para_o_e_mail_mesmo_trocando_de_ip(): void
    {
        config(['security.login_per_hour_per_email' => 3]);

        $this->assertNotSame(429, $this->attempt($this->email));
        $this->assertNotSame(429, $this->attempt($this->email));
        $this->assertNotSame(429, $this->attempt($this->email));
        $this->assertSame(429, $this->attempt($this->email));
    }

    public function test_maiusculas_e_espacos_contam_como_o_mesmo_e_mail(): void
    {
        config(['security.login_per_hour_per_email' => 3]);

        $this->assertNotSame(429, $this->attempt(strtoupper($this->email)));
        $this->assertNotSame(429, $this->attempt(' '.$this->email.' '));
        $this->assertNotSame(429, $this->attempt($this->email));
        $this->assertSame(429, $this->attempt(ucfirst($this->email)));
    }

    public function test_outro_e_mail_nao_e_afetado(): void
    {
        config(['security.login_per_hour_per_email' => 2]);
        $other = 'outro-'.bin2hex(random_bytes(6)).'@dimetal.test';

        $this->attempt($this->email);
        $this->attempt($this->email);
        $this->assertSame(429, $this->attempt($this->email));

        $this->assertNotSame(429, $this->attempt($other));
    }

    public function test_sem_e_mail_o_teto_por_hora_nao_e_dividido_entre_ips(): void
    {
        config(['security.login_per_hour_per_email' => 3]);

        foreach (range(1, 5) as $unused) {
            $this->assertNotSame(429, $this->attempt(''));
        }
    }

    public function test_e_mail_que_nao_e_texto_nao_derruba_o_login(): void
    {
        $status = $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(1, 250).'.1.1'])
            ->post('/login', ['email' => ['a@b.test'], 'password' => ['x']])->getStatusCode();

        $this->assertLessThan(500, $status);
    }

    public function test_a_regra_por_minuto_por_e_mail_e_ip_continua(): void
    {
        $ip = '10.'.random_int(1, 250).'.'.random_int(1, 250).'.'.random_int(1, 250);

        foreach (range(1, 5) as $unused) {
            $this->assertNotSame(429, $this->attempt($this->email, $ip));
        }

        $this->assertSame(429, $this->attempt($this->email, $ip));
    }
}
