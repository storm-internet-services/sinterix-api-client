<?php

namespace Sinterix;

class FileTokenStorage implements TokenStorageInterface
{
    private string $tokenFile;

    public function __construct(string $tokenFile) {
        $this->tokenFile = $tokenFile;
    }

    public function getToken(): ?string {
        if (!file_exists($this->tokenFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->tokenFile), true);
        if (!$data || !isset($data['token'], $data['expires']) || time() >= $data['expires']) {
            return null;
        }

        return $data['token'];
    }

    public function storeToken(string $token, int $expires): void {
        file_put_contents($this->tokenFile, json_encode([
            'token' => $token,
            'expires' => $expires,
        ]));
    }
}