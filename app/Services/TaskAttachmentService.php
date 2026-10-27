<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskStatusHistory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TaskAttachmentService
{
    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, original_name: string, mime_type: ?string, size_bytes: int}>
     */
    public function storeAttachmentFiles(array $files): array
    {
        $storedAttachments = [];

        try {
            foreach ($files as $file) {
                $path = $file->store('task-attachments', 'local');
                if (! is_string($path)) {
                    throw new RuntimeException('Unable to store the task attachment.');
                }

                $storedAttachments[] = [
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                ];
            }
        } catch (Throwable $exception) {
            $this->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        return $storedAttachments;
    }

    /**
     * @param  list<array{path: string, original_name: string, mime_type: ?string, size_bytes: int}>  $files
     */
    public function createAttachmentRecords(Task $task, array $files, int $uploadedBy, ?TaskStatusHistory $history = null): void
    {
        foreach ($files as $file) {
            $task->attachments()->create([
                'status_history_id' => $history?->id,
                'uploaded_by' => $uploadedBy,
                'disk' => 'local',
                ...$file,
            ]);
        }
    }

    /**
     * @param  list<array{path: string, original_name: string, mime_type: ?string, size_bytes: int}>  $files
     */
    public function deleteStoredAttachmentFiles(array $files): void
    {
        $paths = array_column($files, 'path');
        if ($paths !== [] && ! Storage::disk('local')->delete($paths)) {
            throw new RuntimeException('Unable to clean up stored task attachments.');
        }
    }
}
