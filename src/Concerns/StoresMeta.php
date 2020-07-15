<?php

namespace Plank\Checkpoint\Concerns;

trait StoresMeta
{
    public $metaAttributes = [];

    /**
     * Moves data in columns specified in $metaAttributes from the model the revision
     */
    private function handleMeta(&$revision = null)
    {
        $this->metaAttributes = $this->registerMetaAttributes();
        $meta = collect();
        foreach ($this->metaAttributes as $attribute) {
            $meta[$attribute] = $this->$attribute;
            $this->$attribute = null;
        }
        $revision = $revision ?? $this->revision;
        $revision->metadata = $meta->toJson();
        $revision->save();

    }

    public function registerMetaAttributes()
    {
    }
}
