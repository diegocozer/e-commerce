<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Audit\AuditEntry;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\ActorType;
use App\Shared\Domain\Exceptions\InvalidValue;
use App\Shared\Support\HtmlSanitizer;
use App\Shared\Support\Mask;
use App\Shared\Support\PlainText;
use App\Shared\Support\SensitiveData;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function test_masks(): void
    {
        self::assertSame('***.982.247-**', Mask::cpf('52998224725'));
        self::assertSame('**.222.333/0001-**', Mask::cnpj('11222333000181'));
        self::assertSame('jo***@g***.com', Mask::email('joao.silva@gmail.com'));
        self::assertSame('(47) *****-0001', Mask::phone('5547999990001'));
        self::assertSame('***', Mask::cpf('123'));
    }

    public function test_sensitive_data_redaction(): void
    {
        $redacted = SensitiveData::redact([
            'name' => 'Maria',
            'password' => 'secret',
            'nested' => ['access_token' => 'abc', 'cpf' => '52998224725', 'Authorization' => 'Bearer x'],
            'reset_token' => 't',
            'card_number' => '4111',
        ]);

        self::assertSame('Maria', $redacted['name']);
        self::assertSame(SensitiveData::REDACTED, $redacted['password']);
        self::assertSame(SensitiveData::REDACTED, $redacted['nested']['access_token']);
        self::assertSame(SensitiveData::REDACTED, $redacted['nested']['Authorization']);
        self::assertSame('***.982.247-**', $redacted['nested']['cpf']);
        self::assertSame(SensitiveData::REDACTED, $redacted['reset_token']);
        self::assertSame(SensitiveData::REDACTED, $redacted['card_number']);
    }

    public function test_html_sanitizer_keeps_only_the_adr_024_allowlist(): void
    {
        $sanitizer = new HtmlSanitizer;
        $html = '<h2 class="x" style="color:red">Título</h2><p onclick="x()">Texto <strong>forte</strong> <em>ê</em>'
            .'<script>alert(1)</script><img src=x onerror=alert(1)></p>'
            .'<ul><li>a</li></ul><a href="javascript:alert(1)">mal</a><a href="https://example.com" target="_blank">ok</a>'
            .'<table><tr><td>1</td></tr></table><iframe src="https://evil"></iframe><h4>h4</h4>';

        $clean = (string) $sanitizer->sanitize($html);

        self::assertStringContainsString('<h2>Título</h2>', $clean);
        self::assertStringContainsString('<strong>forte</strong>', $clean);
        self::assertStringContainsString('<li>a</li>', $clean);
        self::assertStringContainsString('<a href="https://example.com">ok</a>', $clean);
        self::assertStringContainsString('<td>1</td>', $clean);
        foreach (['script', 'onclick', 'onerror', 'style', 'class', '<img', 'iframe', 'javascript:', '<h4'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $clean);
        }
        self::assertNull($sanitizer->sanitize(null));
        self::assertNull($sanitizer->sanitize('<script>x</script>'));
    }

    public function test_plain_text(): void
    {
        self::assertSame("Olá\nmundo", PlainText::clean("  <b>Olá</b>\n\x07mundo  "));
        self::assertSame("\u{00E9}", PlainText::clean("e\u{0301}")); // NFC
    }

    public function test_actor_ref_and_audit_diff(): void
    {
        self::assertSame('admin:12', (string) ActorRef::admin(12));
        self::assertSame('system', (string) ActorRef::system());

        $entry = AuditEntry::diff(ActorRef::admin(1), 'product.updated', 'product', 10, ['name' => 'A', 'price' => 1], ['name' => 'A', 'price' => 2]);
        self::assertSame(['price' => 1], $entry->oldValues);
        self::assertSame(['price' => 2], $entry->newValues);

        $this->expectException(InvalidValue::class);
        ActorRef::of(ActorType::System, 3);
    }
}
