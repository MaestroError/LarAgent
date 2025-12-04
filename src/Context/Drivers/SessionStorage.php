<?php

namespace LarAgent\Context\Drivers;

use Illuminate\Support\Facades\Session;
use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class SessionStorage extends StorageDriver
{
    /**
     * Read data from session
     *
     * @param SessionIdentity $identity
     * @return array|null
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $key = $identity->getKey();
        $data = Session::get($key);

        if ($data === null) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Write data to session
     *
     * @param SessionIdentity $identity
     * @param array $data
     * @return bool
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        $key = $identity->getKey();
        Session::put($key, $data);

        return true;
    }

    /**
     * Remove data from session
     *
     * @param SessionIdentity $identity
     * @return bool
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        $key = $identity->getKey();
        Session::forget($key);

        return true;
    }
}
