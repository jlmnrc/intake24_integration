<?php namespace Intake24\Intake24Integration;

require_once __DIR__ . '/../../../redcap_connect.php';

class Intake24IntegrationTest extends \ExternalModules\ModuleBaseTest
{
    private $savedAuthHeader;

    public function setUp(): void
    {
        parent::setUp();
        $this->savedAuthHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        if ($this->savedAuthHeader === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = $this->savedAuthHeader;
        }
        parent::tearDown();
    }

    /** Invoke a private/protected method on the module under test. */
    private function callPrivate(string $method, ...$args)
    {
        $m = new \ReflectionMethod(Intake24Integration::class, $method);
        $m->setAccessible(true);
        return $m->invoke($this->module, ...$args);
    }

    // --- test JWT helpers (mirror Intake24's signing) -----------------------

    private function b64url(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function makeJwt(array $claims, string $secret, string $alg = 'HS256'): string
    {
        $h = $this->b64url(json_encode(['typ' => 'JWT', 'alg' => $alg]));
        $p = $this->b64url(json_encode($claims));
        $s = $this->b64url(hash_hmac('sha256', "$h.$p", $secret, true));
        return "$h.$p.$s";
    }

    // --- module smoke test --------------------------------------------------

    function testModuleLoads()
    {
        $this->assertInstanceOf(Intake24Integration::class, $this->module);
    }

    // --- calculateReminderDate ----------------------------------------------

    function testReminderIsThreeDaysLaterSameTimeOnWeekdays()
    {
        // 2026-06-08 is a Monday.
        $this->assertSame(
            '2026-06-11 14:30:00',
            $this->callPrivate('calculateReminderDate', '2026-06-08 14:30:00')
        );
    }

    function testFridayCompletionSchedulesSundayTenAm()
    {
        // 2026-06-12 is a Friday -> Sunday 10:00, not Monday.
        $this->assertSame(
            '2026-06-14 10:00:00',
            $this->callPrivate('calculateReminderDate', '2026-06-12 09:15:00')
        );
    }

    function testReminderAcceptsIntake24SlashDateFormat()
    {
        // The Intake24 notification produces "Y/m/d H:i" strings.
        $this->assertSame(
            '2026-06-13 15:30:00',                       // Wed 2026-06-10 + 3 days
            $this->callPrivate('calculateReminderDate', '2026/06/10 15:30')
        );
        $this->assertSame(
            '2026-06-14 10:00:00',                       // Friday in slash format
            $this->callPrivate('calculateReminderDate', '2026/06/12 15:30')
        );
    }

    function testReminderFallsBackToValidDateOnGarbageInput()
    {
        $result = $this->callPrivate('calculateReminderDate', 'not-a-date');
        $this->assertNotFalse(strtotime($result), "Fallback must still return a parseable date/time");
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    // --- getTokenLifetimeSeconds --------------------------------------------

    function testConfiguredLifetimeIsConvertedToSeconds()
    {
        $this->assertSame(90 * 86400, $this->callPrivate('getTokenLifetimeSeconds', '90'));
        $this->assertSame(30 * 86400, $this->callPrivate('getTokenLifetimeSeconds', 30));
        $this->assertSame(365 * 86400, $this->callPrivate('getTokenLifetimeSeconds', '365'));
    }

    function testOnlyAnExplicitZeroMeansNeverExpires()
    {
        // Null is the signal to omit the "exp" claim entirely. Returning 0 here
        // would put "exp": <now> on the token and kill the link immediately.
        $this->assertNull($this->callPrivate('getTokenLifetimeSeconds', 0));
        $this->assertNull($this->callPrivate('getTokenLifetimeSeconds', '0'));
    }

    function testUnsavedSettingFallsBackToNinetyDayDefault()
    {
        // An untouched dropdown comes back as null or ''. Neither may be mistaken
        // for the explicit "Never expires" choice.
        $this->assertSame(90 * 86400, $this->callPrivate('getTokenLifetimeSeconds', null));
        $this->assertSame(90 * 86400, $this->callPrivate('getTokenLifetimeSeconds', ''));
    }

    function testNegativeOrGarbageLifetimeFallsBackToTheDefault()
    {
        $this->assertSame(90 * 86400, $this->callPrivate('getTokenLifetimeSeconds', -5));
        $this->assertSame(90 * 86400, $this->callPrivate('getTokenLifetimeSeconds', 'not-a-number'));
    }

    function testDefaultIsOverridable()
    {
        $this->assertSame(30 * 86400, $this->callPrivate('getTokenLifetimeSeconds', null, 30));
    }

    function testAbsurdLifetimeIsCapped()
    {
        $this->assertSame(3650 * 86400, $this->callPrivate('getTokenLifetimeSeconds', 999999));
    }

    // --- base64Url helpers --------------------------------------------------

    function testBase64UrlRoundTripsBinaryData()
    {
        $raw = random_bytes(64);
        $encoded = $this->callPrivate('base64UrlEncode', $raw);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertSame($raw, $this->callPrivate('base64UrlDecode', $encoded));
    }

    // --- verifyIntake24Signature (webhook authentication) -------------------

    function testValidSignatureIsAccepted()
    {
        $secret = 'test-secret';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->makeJwt(['submissionId' => 'abc'], $secret);
        $this->assertTrue($this->callPrivate('verifyIntake24Signature', $secret));
    }

    function testWrongSecretIsRejected()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->makeJwt(['submissionId' => 'abc'], 'other-secret');
        $this->assertFalse($this->callPrivate('verifyIntake24Signature', 'test-secret'));
    }

    function testAlgNoneIsRejected()
    {
        // Classic "alg":"none" bypass: even correctly HMAC'd, a non-HS256 header must fail.
        $secret = 'test-secret';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->makeJwt(['submissionId' => 'abc'], $secret, 'none');
        $this->assertFalse($this->callPrivate('verifyIntake24Signature', $secret));
    }

    function testExpiredTokenIsRejected()
    {
        $secret = 'test-secret';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->makeJwt(['exp' => time() - 3600], $secret);
        $this->assertFalse($this->callPrivate('verifyIntake24Signature', $secret));
    }

    function testUnexpiredTokenWithExpIsAccepted()
    {
        $secret = 'test-secret';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->makeJwt(['exp' => time() + 3600], $secret);
        $this->assertTrue($this->callPrivate('verifyIntake24Signature', $secret));
    }

    function testMissingAuthorizationHeaderIsRejected()
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->assertFalse($this->callPrivate('verifyIntake24Signature', 'test-secret'));
    }

    function testEmptySecretIsRejected()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->makeJwt(['a' => 1], 'anything');
        $this->assertFalse($this->callPrivate('verifyIntake24Signature', ''));
    }

    function testMalformedTokenIsRejected()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not.a-real-token';
        $this->assertFalse($this->callPrivate('verifyIntake24Signature', 'test-secret'));
    }

    // --- getSaveErrors (crash-proof saveData response handling) -------------

    function testSaveErrorsExtractedFromArrayResponse()
    {
        $this->assertSame(
            ['field x is invalid'],
            $this->callPrivate('getSaveErrors', ['errors' => ['field x is invalid']])
        );
    }

    function testSaveErrorsWrapsScalarError()
    {
        $this->assertSame(
            ['single error'],
            $this->callPrivate('getSaveErrors', ['errors' => 'single error'])
        );
    }

    function testSaveErrorsDecodesJsonStringResponse()
    {
        $this->assertSame(
            ['bad thing'],
            $this->callPrivate('getSaveErrors', '{"errors":["bad thing"]}')
        );
    }

    function testSaveErrorsIsEmptyForCleanOrDegenerateResponses()
    {
        // None of these may throw on PHP 8 (count(null) etc.) — that regression
        // is exactly what this method exists to prevent.
        $this->assertSame([], $this->callPrivate('getSaveErrors', ['errors' => []]));
        $this->assertSame([], $this->callPrivate('getSaveErrors', []));
        $this->assertSame([], $this->callPrivate('getSaveErrors', null));
        $this->assertSame([], $this->callPrivate('getSaveErrors', false));
        $this->assertSame([], $this->callPrivate('getSaveErrors', 'not json at all'));
        $this->assertSame([], $this->callPrivate('getSaveErrors', ['ids' => [1]]));
    }
}
