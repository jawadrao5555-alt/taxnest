<?php

namespace Tests\Feature;

use App\Services\FbrService;
use ReflectionMethod;
use Tests\TestCase;

class DiAcknowledgementClassificationTest extends TestCase
{
    public function test_valid_without_reference_is_not_a_rejection(): void
    {
        $method = new ReflectionMethod(FbrService::class, 'isExplicitFbrRejection');
        $this->assertFalse($method->invoke(new FbrService(), [
            'validationResponse' => ['statusCode' => '00', 'status' => 'valid'],
        ]));
    }

    public function test_partial_reference_and_server_fault_are_not_replayable_rejections(): void
    {
        $method = new ReflectionMethod(FbrService::class, 'isExplicitFbrRejection');
        $service = new FbrService();
        foreach ([
            ['invoiceNumber' => 'ACK-1', 'validationResponse' => ['statusCode' => '01', 'status' => 'invalid']],
            ['validationResponse' => ['statusCode' => '01', 'status' => 'invalid', 'invoiceStatuses' => [['invoiceNo' => 'ACK-2']]]],
            ['validationResponse' => ['statusCode' => '01', 'status' => 'invalid', 'errorCode' => '500']],
            ['validationResponse' => []],
            ['fault' => ['message' => 'Unknown']],
        ] as $response) {
            $this->assertFalse($method->invoke($service, $response));
        }
    }

    public function test_explicit_invalid_without_any_reference_remains_retryable(): void
    {
        $method = new ReflectionMethod(FbrService::class, 'isExplicitFbrRejection');
        $this->assertTrue($method->invoke(new FbrService(), [
            'validationResponse' => ['statusCode' => '01', 'status' => 'invalid', 'errorCode' => '0099', 'error' => 'Invalid UOM'],
        ]));
    }
}
