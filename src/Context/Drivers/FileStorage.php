<?php

namespace LarAgent\Context\Drivers;

use Illuminate\Support\Facades\Storage;
use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class FileStorage extends StorageDriver
{
    /**
     * The storage disk to use
     */
    protected string $disk;

    /**
     * The folder path to store files
     */
    protected string $folder;

    /**
     * Create a new FileDriver instance
     *
     * @param  string|null  $disk  The storage disk to use (null for default)
     * @param  string  $folder  The folder path to store files
     */
    public function __construct(?string $disk = null, string $folder = 'laragent_storage')
    {
        $this->disk = $disk ?? config('filesystems.default', 'local');
        $this->folder = $folder;
    }

    /**
     * Read data from file storage
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $filePath = $this->getFullPath($identity);

        if (! Storage::disk($this->disk)->exists($filePath)) {
            return null;
        }

        try {
            $content = Storage::disk($this->disk)->get($filePath);
            $data = json_decode($content, true);

            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Write data to file storage
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        $filePath = $this->getFullPath($identity);

        try {
            $this->ensureFolderExists();
            Storage::disk($this->disk)->put($filePath, json_encode($data, JSON_PRETTY_PRINT));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Remove data from file storage
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        $filePath = $this->getFullPath($identity);

        try {
            if (Storage::disk($this->disk)->exists($filePath)) {
                Storage::disk($this->disk)->delete($filePath);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the full path for a file
     */
    protected function getFullPath(SessionIdentity $identity): string
    {
        $safeName = $this->getSafeName($identity->getKey());

        return $this->folder.'/'.$safeName.'.json';
    }

    /**
     * Sanitize a key to be safe for file names
     */
    protected function getSafeName(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
    }

    /**
     * Ensure the storage folder exists
     */
    protected function ensureFolderExists(): void
    {
        if (! Storage::disk($this->disk)->exists($this->folder)) {
            Storage::disk($this->disk)->makeDirectory($this->folder);
        }
    }
}
