<?php
namespace Sinterix;

interface TokenStorageInterface {

    public function getToken(): ?string;

    public function storeToken(string $token, int $expires): void;

}