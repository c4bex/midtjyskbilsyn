<?php

namespace App\Services;

class ArvoTaskService
{
    public function send(array $task): array
    {
        if (! config('services.arvo.enabled')) {
            return ['sent' => false, 'status' => 'draft', 'message' => 'ARVO-integrationen er ikke aktiveret. Opgaven er gemt som kladde.'];
        }

        return ['sent' => false, 'status' => 'draft', 'message' => 'ARVO-adapteren afventer dokumenteret API og testdata.'];
    }
}
