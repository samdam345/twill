<?php

namespace A17\Twill\Repositories\Behaviors;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait HandleRepeaters
{
    /**
     * @param \A17\Twill\Models\Model $object
     * @param array $fields
     * @return void
     */
    public function afterSaveHandleRepeaters($object, $fields)
    {
        if (property_exists($this, 'repeaters')) {
            foreach ($this->repeaters as $moduleKey => $module) {
                if (is_string($module)) {
                    $model = Str::studly(Str::singular($module));
                    $repeaterName = Str::singular($module);
                    $this->updateRepeater($object, $fields, $module, $model, $repeaterName);
                } elseif (is_array($module)) {
                    $relation = !empty($module['relation']) ? $module['relation'] : $moduleKey;
                    $model = isset($module['model']) ? $module['model'] : Str::studly(Str::singular($moduleKey));
                    $repeaterName = !empty($module['repeaterName']) ? $module['repeaterName'] : Str::singular($moduleKey);
                    $this->updateRepeater($object, $fields, $relation, $model, $repeaterName);
                }
            }
        }
    }

    /**
     * @param \A17\Twill\Models\Model $object
     * @param array $fields
     * @return array
     */
    public function getFormFieldsHandleRepeaters($object, $fields)
    {
        if (property_exists($this, 'repeaters')) {
            foreach ($this->repeaters as $moduleKey => $module) {
                if (is_string($module)) {
                    $model = Str::studly(Str::singular($module));
                    $repeaterName = Str::singular($module);
                    $fields = $this->getFormFieldsForRepeater($object, $fields, $module, $model, $repeaterName);
                } elseif (is_array($module)) {
                    $model = isset($module['model']) ? $module['model'] : Str::studly(Str::singular($moduleKey));
                    $relation = !empty($module['relation']) ? $module['relation'] : $moduleKey;
                    $repeaterName = !empty($module['repeaterName']) ? $module['repeaterName'] : Str::singular($moduleKey);
                    $fields = $this->getFormFieldsForRepeater($object, $fields, $relation, $model, $repeaterName);
                }
            }
        }

        return $fields;
    }

    public function updateRepeaterMany($object, $fields, $relation, $keepExisting = true, $model = null)
    {
        $relationFields = $fields['repeaters'][$relation] ?? [];
        $relationRepository = $this->getModelRepository($relation, $model);

        if (!$keepExisting) {
            $object->$relation()->each(function ($repeaterElement) {
                $repeaterElement->forceDelete();
            });
        }

        foreach ($relationFields as $relationField) {
            $newRelation = $relationRepository->create($relationField);
            $object->$relation()->attach($newRelation->id);
        }
    }


    public function updateRepeaterMorphMany($object, $fields, $relation, $morph = null, $model = null)
    {
        $relationFields = $fields['repeaters'][$relation] ?? [];
        $relationRepository = $this->getModelRepository($relation, $model);

        $morph = $morph ?: $relation;

        $morphFieldType = $morph.'_type';
        $morphFieldId = $morph.'_id';

        // if no relation field submitted, soft deletes all associated rows
        if (!$relationFields) {
            $relationRepository->updateBasic(null, [
                'deleted_at' => Carbon::now(),
            ], [
                $morphFieldType => $object->getMorphClass(),
                $morphFieldId => $object->id,
            ]);
        }

        // keep a list of updated and new rows to delete (soft delete?) old rows that were deleted from the frontend
        $currentIdList = [];

        foreach ($relationFields as $index => $relationField) {
            $relationField['position'] = $index + 1;
            if (isset($relationField['id']) && Str::startsWith($relationField['id'], $relation)) {
                // row already exists, let's update
                $id = str_replace($relation . '-', '', $relationField['id']);
                $relationRepository->update($id, $relationField);
                $currentIdList[] = $id;
            } else {
                // new row, let's attach to our object and create
                unset($relationField['id']);
                $newRelation = $relationRepository->create($relationField);
                $object->$relation()->save($newRelation);
                $currentIdList[] = $newRelation['id'];
            }
        }

        foreach ($object->$relation->pluck('id') as $id) {
            if (!in_array($id, $currentIdList)) {
                $relationRepository->updateBasic(null, [
                    'deleted_at' => Carbon::now(),
                ], [
                    'id' => $id,
                ]);
            }
        }
    }

    public function updateRepeater($object, $fields, $relation, $model = null, $repeaterName = null)
    {
        if (!$repeaterName) {
            $repeaterName = $relation;
        }

        $relationFields = $fields['repeaters'][$repeaterName] ?? [];

        $relationRepository = $this->getModelRepository($relation, $model);

        // if no relation field submitted, soft deletes all associated rows
        if (!$relationFields) {
            $relationRepository->updateBasic(null, [
                'deleted_at' => Carbon::now(),
            ], [
                $this->model->getForeignKey() => $object->id,
            ]);
        }

        // keep a list of updated and new rows to delete (soft delete?) old rows that were deleted from the frontend
        $currentIdList = [];

        foreach ($relationFields as $index => $relationField) {
            $relationField['position'] = $index + 1;
            if (isset($relationField['id']) && Str::startsWith($relationField['id'], $relation)) {
                // row already exists, let's update
                $id = str_replace($relation . '-', '', $relationField['id']);
                $relationRepository->update($id, $relationField);
                $currentIdList[] = $id;
            } else {
                // new row, let's attach to our object and create
                $relationField[$this->model->getForeignKey()] = $object->id;
                unset($relationField['id']);
                $newRelation = $relationRepository->create($relationField);
                $currentIdList[] = $newRelation['id'];
            }
        }

        foreach ($object->$relation->pluck('id') as $id) {
            if (!in_array($id, $currentIdList)) {
                $relationRepository->updateBasic(null, [
                    'deleted_at' => Carbon::now(),
                ], [
                    'id' => $id,
                ]);
            }
        }
    }

    public function getFormFieldsForRepeater($object, $fields, $relation, $model = null, $repeaterName = null)
    {
        if (!$repeaterName) {
            $repeaterName = $relation;
        }

        $repeaters = [];
        $repeatersFields = [];
        $repeatersBrowsers = [];
        $repeatersMedias = [];
        $repeatersFiles = [];
        $relationRepository = $this->getModelRepository($relation, $model);
        $repeatersConfig = config('twill.block_editor.repeaters');

        foreach ($object->$relation as $relationItem) {
            $repeaters[] = [
                'id' => $relation . '-' . $relationItem->id,
                'type' => $repeatersConfig[$repeaterName]['component'],
                'title' => $repeatersConfig[$repeaterName]['title'],
            ];

            $relatedItemFormFields = $relationRepository->getFormFields($relationItem);
            $translatedFields = [];

            if (isset($relatedItemFormFields['translations'])) {
                foreach ($relatedItemFormFields['translations'] as $key => $values) {
                    $repeatersFields[] = [
                        'name' => "blocks[$relation-$relationItem->id][$key]",
                        'value' => $values,
                    ];

                    $translatedFields[] = $key;
                }
            }

            if (isset($relatedItemFormFields['medias'])) {
                if (config('twill.media_library.translated_form_fields', false)) {
                    Collection::make($relatedItemFormFields['medias'])->each(function ($rolesWithMedias, $locale) use (&$repeatersMedias, $relation, $relationItem) {
                        $repeatersMedias[] = Collection::make($rolesWithMedias)->mapWithKeys(function ($medias, $role) use ($locale, $relation, $relationItem) {
                            return [
                                "blocks[$relation-$relationItem->id][$role][$locale]" => $medias,
                            ];
                        })->toArray();
                    });
                } else {
                    foreach ($relatedItemFormFields['medias'] as $key => $values) {
                        $repeatersMedias["blocks[$relation-$relationItem->id][$key]"] = $values;
                    }
                }
            }

            if (isset($relatedItemFormFields['files'])) {
                Collection::make($relatedItemFormFields['files'])->each(function ($rolesWithFiles, $locale) use (&$repeatersFiles, $relation, $relationItem) {
                    $repeatersFiles[] = Collection::make($rolesWithFiles)->mapWithKeys(function ($files, $role) use ($locale, $relation, $relationItem) {
                        return [
                            "blocks[$relation-$relationItem->id][$role][$locale]" => $files,
                        ];
                    })->toArray();
                });
            }

            if (isset($relatedItemFormFields['browsers'])) {
                foreach ($relatedItemFormFields['browsers'] as $key => $values) {
                    $repeatersBrowsers["blocks[$relation-$relationItem->id][$key]"] = $values;
                }
            }

            $itemFields = method_exists($relationItem, 'toRepeaterArray') ? $relationItem->toRepeaterArray() : Arr::except($relationItem->attributesToArray(), $translatedFields);

            foreach ($itemFields as $key => $value) {
                $repeatersFields[] = [
                    'name' => "blocks[$relation-$relationItem->id][$key]",
                    'value' => $value,
                ];
            }

        }

        if (!empty($repeatersMedias) && config('twill.media_library.translated_form_fields', false)) {
            $repeatersMedias = call_user_func_array('array_merge', $repeatersMedias);
        }

        if (!empty($repeatersFiles)) {
            $repeatersFiles = call_user_func_array('array_merge', $repeatersFiles);
        }

        $fields['repeaters'][$repeaterName] = $repeaters;
        $fields['repeaterFields'][$repeaterName] = $repeatersFields;
        $fields['repeaterMedias'][$repeaterName] = $repeatersMedias;
        $fields['repeaterFiles'][$repeaterName] = $repeatersFiles;
        $fields['repeaterBrowsers'][$repeaterName] = $repeatersBrowsers;

        return $fields;
    }
}
