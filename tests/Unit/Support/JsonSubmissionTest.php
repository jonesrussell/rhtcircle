<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\JsonSubmission;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class JsonSubmissionTest extends TestCase
{
    public function testMalformedAndStructuredFieldValuesAreRejected(): void
    {
        foreach (['[]', '{', '{"email":["private@example.invalid"]}', '{"email":{"value":"x"}}'] as $body) {
            self::assertNull(JsonSubmission::read(Request::create('/api/contact', 'POST', content: $body), 1024));
        }
    }

    public function testSizeLimitAndBooleanTypesArePreserved(): void
    {
        $request = Request::create('/api/signup', 'POST', content: '{"consent":false,"email":"test@example.invalid"}');
        self::assertNull(JsonSubmission::read($request, 8));
        self::assertSame(false, JsonSubmission::read($request, 1024)['consent']);
    }
}
