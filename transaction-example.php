<?php

// Use a transaction for data integrity
DB::transaction(function () use ($session, $data) {
    // 1. Delete existing messages (1 query)
    $session->{$this->messageRelation}()->delete();

    if (! empty($data)) {
        // 2. Prepare for bulk insert (Optimization over createMany)
        $records = [];
        $now = now();

        foreach ($data as $item) {
            // Create a model instance to properly resolve FK and default attributes
            // This respects $fillable/$guarded properties of the model
            $model = $session->{$this->messageRelation}()->make($item);

            // Manually add timestamps since insert() skips them
            if ($model->usesTimestamps()) {
                $model->setCreatedAt($now);
                $model->setUpdatedAt($now);
            }

            $records[] = $model->getAttributes();
        }

        // 3. Bulk insert (1 query)
        $session->{$this->messageRelation}()->insert($records);
    }
});
