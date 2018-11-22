<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\behaviors\ContentBehavior;
use craft\behaviors\ElementQueryBehavior;
use craft\db\Query;
use craft\errors\FieldNotFoundException;
use craft\errors\MissingComponentException;
use craft\events\ConfigEvent;
use craft\events\FieldEvent;
use craft\events\FieldGroupEvent;
use craft\events\FieldLayoutEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\fields\Assets as AssetsField;
use craft\fields\Categories as CategoriesField;
use craft\fields\Checkboxes as CheckboxesField;
use craft\fields\Color as ColorField;
use craft\fields\Date as DateField;
use craft\fields\Dropdown as DropdownField;
use craft\fields\Email as EmailField;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch as LightswitchField;
use craft\fields\Matrix as MatrixField;
use craft\fields\MissingField;
use craft\fields\MultiSelect as MultiSelectField;
use craft\fields\Number as NumberField;
use craft\fields\PlainText as PlainTextField;
use craft\fields\RadioButtons as RadioButtonsField;
use craft\fields\Table as TableField;
use craft\fields\Tags as TagsField;
use craft\fields\Url as UrlField;
use craft\fields\Users as UsersField;
use craft\helpers\ArrayHelper;
use craft\helpers\Component as ComponentHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\FieldGroup;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\records\Field as FieldRecord;
use craft\records\FieldGroup as FieldGroupRecord;
use craft\records\FieldLayout as FieldLayoutRecord;
use craft\records\FieldLayoutField as FieldLayoutFieldRecord;
use craft\records\FieldLayoutTab as FieldLayoutTabRecord;
use yii\base\Component;
use yii\base\Exception;

/**
 * Fields service.
 * An instance of the Fields service is globally accessible in Craft via [[\craft\base\ApplicationTrait::getFields()|`Craft::$app->fields`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Fields extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event RegisterComponentTypesEvent The event that is triggered when registering field types.
     *
     * Field types must implement [[FieldInterface]]. [[Field]] provides a base implementation.
     *
     * See [Field Types](https://docs.craftcms.com/v3/field-types.html) for documentation on creating field types.
     * ---
     * ```php
     * use craft\events\RegisterComponentTypesEvent;
     * use craft\services\Fields;
     * use yii\base\Event;
     *
     * Event::on(Fields::class,
     *     Fields::EVENT_REGISTER_FIELD_TYPES,
     *     function(RegisterComponentTypesEvent $event) {
     *         $event->types[] = MyFieldType::class;
     *     }
     * );
     * ```
     */
    const EVENT_REGISTER_FIELD_TYPES = 'registerFieldTypes';

    /**
     * @event FieldGroupEvent The event that is triggered before a field group is saved.
     */
    const EVENT_BEFORE_SAVE_FIELD_GROUP = 'beforeSaveFieldGroup';

    /**
     * @event FieldGroupEvent The event that is triggered after a field group is saved.
     */
    const EVENT_AFTER_SAVE_FIELD_GROUP = 'afterSaveFieldGroup';

    /**
     * @event FieldGroupEvent The event that is triggered before a field group is deleted.
     */
    const EVENT_BEFORE_DELETE_FIELD_GROUP = 'beforeDeleteFieldGroup';

    /**
     * @event FieldGroupEvent The event that is triggered after a field group is deleted.
     */
    const EVENT_AFTER_DELETE_FIELD_GROUP = 'afterDeleteFieldGroup';

    /**
     * @event FieldEvent The event that is triggered before a field is saved.
     */
    const EVENT_BEFORE_SAVE_FIELD = 'beforeSaveField';

    /**
     * @event FieldEvent The event that is triggered after a field is saved.
     */
    const EVENT_AFTER_SAVE_FIELD = 'afterSaveField';

    /**
     * @event FieldEvent The event that is triggered before a field is deleted.
     */
    const EVENT_BEFORE_DELETE_FIELD = 'beforeDeleteField';

    /**
     * @event FieldEvent The event that is triggered after a field is deleted.
     */
    const EVENT_AFTER_DELETE_FIELD = 'afterDeleteField';

    /**
     * @event FieldLayoutEvent The event that is triggered before a field layout is saved.
     */
    const EVENT_BEFORE_SAVE_FIELD_LAYOUT = 'beforeSaveFieldLayout';

    /**
     * @event FieldLayoutEvent The event that is triggered after a field layout is saved.
     */
    const EVENT_AFTER_SAVE_FIELD_LAYOUT = 'afterSaveFieldLayout';

    /**
     * @event FieldLayoutEvent The event that is triggered before a field layout is deleted.
     */
    const EVENT_BEFORE_DELETE_FIELD_LAYOUT = 'beforeDeleteFieldLayout';

    /**
     * @event FieldLayoutEvent The event that is triggered after a field layout is deleted.
     */
    const EVENT_AFTER_DELETE_FIELD_LAYOUT = 'afterDeleteFieldLayout';

    const CONFIG_FIELDGROUP_KEY = 'fieldGroups';
    const CONFIG_FIELDS_KEY = 'fields';

    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public $oldFieldColumnPrefix = 'field_';

    /**
     * @var bool Whether to ignore changes to the project config.
     */
    public $ignoreProjectConfigChanges = false;

    /**
     * @var
     */
    private $_groupsById;

    /**
     * @var bool
     */
    private $_fetchedAllGroups = false;

    /**
     * @var
     */
    private $_fieldRecordsById;

    /**
     * @var
     */
    private $_fieldsById;

    /**
     * @var
     */
    private $_allFieldHandlesByContext;

    /**
     * @var
     */
    private $_allFieldsInContext;

    /**
     * @var
     */
    private $_fieldsByContextAndHandle;

    /**
     * @var
     */
    private $_fieldsWithContent;

    /**
     * @var
     */
    private $_layoutsById;

    /**
     * @var
     */
    private $_layoutsByType;

    /**
     * @var array
     */
    private $_savingFields = [];

    // Public Methods
    // =========================================================================

    // Groups
    // -------------------------------------------------------------------------

    /**
     * Returns all field groups.
     *
     * @return FieldGroup[] The field groups
     */
    public function getAllGroups(): array
    {
        if ($this->_fetchedAllGroups) {
            return array_values($this->_groupsById);
        }

        $this->_groupsById = [];
        $results = $this->_createGroupQuery()->all();

        foreach ($results as $result) {
            $group = new FieldGroup($result);
            $this->_groupsById[$group->id] = $group;
        }

        $this->_fetchedAllGroups = true;

        return array_values($this->_groupsById);
    }

    /**
     * Returns a field group by its ID.
     *
     * @param int $groupId The field group’s ID
     * @return FieldGroup|null The field group, or null if it doesn’t exist
     */
    public function getGroupById(int $groupId)
    {
        if ($this->_groupsById !== null && array_key_exists($groupId, $this->_groupsById)) {
            return $this->_groupsById[$groupId];
        }

        if ($this->_fetchedAllGroups) {
            return null;
        }

        $result = $this->_createGroupQuery()
            ->where(['id' => $groupId])
            ->one();

        return $this->_groupsById[$groupId] = $result ? new FieldGroup($result) : null;
    }

    /**
     * Saves a field group.
     *
     * @param FieldGroup $group The field group to be saved
     * @param bool $runValidation Whether the group should be validated
     * @return bool Whether the field group was saved successfully
     */
    public function saveGroup(FieldGroup $group, bool $runValidation = true): bool
    {
        $isNewGroup = !$group->id;

        // Fire a 'beforeSaveFieldGroup' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_FIELD_GROUP)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_FIELD_GROUP, new FieldGroupEvent([
                'group' => $group,
                'isNew' => $isNewGroup,
            ]));
        }

        if ($runValidation && !$group->validate()) {
            Craft::info('Field group not saved due to validation error.', __METHOD__);
            return false;
        }

        $projectConfig = Craft::$app->getProjectConfig();

        $configData = [
            'name' => $group->name
        ];

        if ($isNewGroup) {
            $uid = StringHelper::UUID();
        } else {
            $groupRecord = $this->_getGroupRecord($group->id);
            $uid = $groupRecord->uid;
        }

        $projectConfig->set(self::CONFIG_FIELDGROUP_KEY . '.' . $uid, $configData);

        if ($isNewGroup) {
            $group->id = Db::idByUid('{{%fieldgroups}}', $uid);
        }

        $group->uid = $uid;

        return true;
    }

    /**
     * Handle field group change
     *
     * @param ConfigEvent $event
     */
    public function handleChangedGroup(ConfigEvent $event)
    {
        if ($this->ignoreProjectConfigChanges) {
            return;
        }

        $data = $event->newValue;
        $uid = $event->tokenMatches[0];

        $groupRecord = $this->_getGroupRecord($uid);

        // If this is a new group, set the UID we want.
        if (!$groupRecord->id) {
            $groupRecord->uid = $uid;
        }

        $groupRecord->name = $data['name'];
        $groupRecord->save(false);

        $this->_fetchedAllGroups = false;

        // Fire an 'afterSaveFieldGroup' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_FIELD_GROUP)) {
            $this->trigger(self::EVENT_AFTER_SAVE_FIELD_GROUP, new FieldGroupEvent([
                'group' => $this->getGroupById($groupRecord->id),
                'isNew' => $isNewGroup,
            ]));
        }
    }

    /**
     * Handle field group getting deleted.
     *
     * @param ConfigEvent $event
     */
    public function handleDeletedGroup(ConfigEvent $event)
    {
        if ($this->ignoreProjectConfigChanges) {
            return;
        }

        $uid = $event->tokenMatches[0];
        $groupRecord = $this->_getGroupRecord($uid);

        if (!$groupRecord->id) {
            return;
        }

        $this->_fetchedAllGroups = false;
        $group = $this->getGroupById($groupRecord->id);

        $groupRecord->delete();

        // Delete our cache of it
        unset($this->_groupsById[$groupRecord->id]);

        // Fire an 'afterDeleteFieldGroup' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_FIELD_GROUP)) {
            $this->trigger(self::EVENT_AFTER_DELETE_FIELD_GROUP, new FieldGroupEvent([
                'group' => $group
            ]));
        }
    }

    /**
     * Deletes a field group by its ID.
     *
     * @param int $groupId The field group’s ID
     * @return bool Whether the field group was deleted successfully
     */
    public function deleteGroupById(int $groupId): bool
    {
        $group = $this->getGroupById($groupId);

        if (!$group) {
            return false;
        }

        return $this->deleteGroup($group);
    }

    /**
     * Deletes a field group.
     *
     * @param FieldGroup $group The field group
     * @return bool Whether the field group was deleted successfully
     */
    public function deleteGroup(FieldGroup $group): bool
    {
        /** @var FieldGroupRecord $groupRecord */
        $groupRecord = FieldGroupRecord::find()
            ->where(['id' => $group->id])
            ->with('fields')
            ->one();

        if (!$groupRecord) {
            return false;
        }

        // Fire a 'beforeDeleteFieldGroup' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_FIELD_GROUP)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_FIELD_GROUP, new FieldGroupEvent([
                'group' => $group
            ]));
        }

        // Manually delete the fields (rather than relying on cascade deletes) so we have a chance to delete the
        // content columns
        /** @var Field[] $fields */
        $fields = $this->getFieldsByGroupId($group->id);

        foreach ($fields as $field) {
            $this->deleteField($field);
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_FIELDGROUP_KEY . '.' . $group->uid);

        return true;
    }

    // Fields
    // -------------------------------------------------------------------------

    /**
     * Returns all available field type classes.
     *
     * @return string[] The available field type classes
     */
    public function getAllFieldTypes(): array
    {
        $fieldTypes = [
            AssetsField::class,
            CategoriesField::class,
            CheckboxesField::class,
            ColorField::class,
            DateField::class,
            DropdownField::class,
            EmailField::class,
            EntriesField::class,
            LightswitchField::class,
            MatrixField::class,
            MultiSelectField::class,
            NumberField::class,
            PlainTextField::class,
            RadioButtonsField::class,
            TableField::class,
            TagsField::class,
            UrlField::class,
            UsersField::class,
        ];

        $event = new RegisterComponentTypesEvent([
            'types' => $fieldTypes
        ]);
        $this->trigger(self::EVENT_REGISTER_FIELD_TYPES, $event);

        return $event->types;
    }

    /**
     * Returns all field types that have a column in the content table.
     *
     * @return string[] The field type classes
     */
    public function getFieldTypesWithContent(): array
    {
        $fieldTypes = [];

        foreach ($this->getAllFieldTypes() as $fieldType) {
            /** @var Field|string $fieldType */
            if ($fieldType::hasContentColumn()) {
                $fieldTypes[] = $fieldType;
            }
        }

        return $fieldTypes;
    }

    /**
     * Returns all field types whose column types are considered compatible with a given field.
     *
     * @param FieldInterface $field The current field to base compatible fields on
     * @param bool $includeCurrent Whether $field's class should be included
     * @return string[] The compatible field type classes
     */
    public function getCompatibleFieldTypes(FieldInterface $field, bool $includeCurrent = true): array
    {
        /** @var Field $field */
        if (!$field::hasContentColumn()) {
            return $includeCurrent ? [get_class($field)] : [];
        }

        // If the field has any validation errors and has an ID, swap it with the saved field
        if (!$field->getIsNew() && $field->hasErrors()) {
            $field = $this->getFieldById($field->id);
        }

        $types = [];
        $fieldColumnType = $field->getContentColumnType();

        foreach ($this->getAllFieldTypes() as $class) {
            if ($class === get_class($field)) {
                if ($includeCurrent) {
                    $types[] = $class;
                }
                continue;
            }

            if (!$class::hasContentColumn()) {
                continue;
            }

            /** @var FieldInterface $tempField */
            $tempField = new $class();
            if (!Db::areColumnTypesCompatible($fieldColumnType, $tempField->getContentColumnType())) {
                continue;
            }

            $types[] = $class;
        }

        // Make sure the current field class is in there if it's supposed to be
        if ($includeCurrent && !in_array(get_class($field), $types, true)) {
            $types[] = get_class($field);
        }

        return $types;
    }

    /**
     * Creates a field with a given config.
     *
     * @param mixed $config The field’s class name, or its config, with a `type` value and optionally a `settings` value
     * @return FieldInterface The field
     */
    public function createField($config): FieldInterface
    {
        if (is_string($config)) {
            $config = ['type' => $config];
        }

        if (!empty($config['id']) && empty($config['uid']) && is_numeric($config['id'])) {
            $uid = Db::uidById('{{%fields}}', $config['id']);
            $config['uid'] = $uid;
        }

        try {
            /** @var Field $field */
            $field = ComponentHelper::createComponent($config, FieldInterface::class);
        } catch (MissingComponentException $e) {
            $config['errorMessage'] = $e->getMessage();
            $config['expectedType'] = $config['type'];
            unset($config['type']);

            $field = new MissingField($config);
        }

        return $field;
    }

    /**
     * Returns all fields within a field context(s).
     *
     * @param string|string[]|null $context The field context(s) to fetch fields from. Defaults to {@link ContentService::$fieldContext}.
     * @return FieldInterface[] The fields
     */
    public function getAllFields($context = null): array
    {
        if ($context === null) {
            $context = [Craft::$app->getContent()->fieldContext];
        } else if (!is_array($context)) {
            $context = (array)$context;
        }

        $missingContexts = [];

        /** @noinspection ForeachSourceInspection - FP */
        foreach ($context as $c) {
            if (!isset($this->_allFieldsInContext[$c])) {
                $missingContexts[] = $c;
                $this->_allFieldsInContext[$c] = [];
            }
        }

        if (!empty($missingContexts)) {
            $results = $this->_createFieldQuery()
                ->where(['fields.context' => $missingContexts])
                ->all();

            foreach ($results as $result) {
                /** @var Field $field */
                $field = $this->createField($result);

                $this->_allFieldsInContext[$field->context][] = $field;
                $this->_fieldsById[$field->id] = $field;
                $this->_fieldsByContextAndHandle[$field->context][$field->handle] = $field;
            }
        }

        $fields = [];

        /** @noinspection ForeachSourceInspection - FP */
        foreach ($context as $c) {
            foreach ($this->_allFieldsInContext[$c] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Returns all fields that have a column in the content table.
     *
     * @return FieldInterface[] The fields
     */
    public function getFieldsWithContent(): array
    {
        $context = Craft::$app->getContent()->fieldContext;

        if (!isset($this->_fieldsWithContent[$context])) {
            $this->_fieldsWithContent[$context] = [];

            foreach ($this->getAllFields() as $field) {
                if ($field::hasContentColumn()) {
                    $this->_fieldsWithContent[$context][] = $field;
                }
            }
        }

        return $this->_fieldsWithContent[$context];
    }

    /**
     * Returns a field by its ID.
     *
     * @param int $fieldId The field’s ID
     * @return FieldInterface|null The field, or null if it doesn’t exist
     */
    public function getFieldById(int $fieldId)
    {
        if ($this->_fieldsById !== null && array_key_exists($fieldId, $this->_fieldsById)) {
            return $this->_fieldsById[$fieldId];
        }

        $result = $this->_createFieldQuery()
            ->where(['fields.id' => $fieldId])
            ->one();

        if (!$result) {
            return $this->_fieldsById[$fieldId] = null;
        }

        /** @var Field $field */
        $field = $this->createField($result);
        $this->_fieldsById[$fieldId] = $field;
        $this->_fieldsByContextAndHandle[$field->context][$field->handle] = $field;

        return $field;
    }

    /**
     * Returns a field by its UID.
     *
     * @param string $fieldUid The field’s UID
     * @return FieldInterface|null The field, or null if it doesn’t exist
     */
    public function getFieldByUid(string $fieldUid)
    {
        $result = $this->_createFieldQuery()
            ->where(['fields.uid' => $fieldUid])
            ->one();

        if (!$result) {
            return null;
        }

        /** @var Field $field */
        $field = $this->createField($result);
        $this->_fieldsById[$field->id] = $field;
        $this->_fieldsByContextAndHandle[$field->context][$field->handle] = $field;

        return $field;
    }

    /**
     * Returns a field by its handle.
     *
     * ---
     *
     * ```php
     * $body = Craft::$app->fields->getFieldByHandle('body');
     * ```
     * ```twig
     * {% set body = craft.app.fields.getFieldByHandle('body') %}
     * {{ body.instructions }}
     * ```
     *
     * @param string $handle The field’s handle
     * @return FieldInterface|null The field, or null if it doesn’t exist
     */
    public function getFieldByHandle(string $handle)
    {
        $context = Craft::$app->getContent()->fieldContext;

        if (!isset($this->_fieldsByContextAndHandle[$context]) || !array_key_exists($handle, $this->_fieldsByContextAndHandle[$context])) {
            // Guilty until proven innocent
            $this->_fieldsByContextAndHandle[$context][$handle] = null;

            if ($this->doesFieldWithHandleExist($handle, $context)) {
                $result = $this->_createFieldQuery()
                    ->where([
                        'fields.handle' => $handle,
                        'fields.context' => $context
                    ])
                    ->one();

                if ($result) {
                    /** @var Field $field */
                    $field = $this->createField($result);
                    $this->_fieldsById[$field->id] = $field;
                    $this->_fieldsByContextAndHandle[$context][$field->handle] = $field;
                }
            }
        }

        return $this->_fieldsByContextAndHandle[$context][$handle];
    }

    /**
     * Returns whether a field exists with a given handle and context.
     *
     * @param string $handle The field handle
     * @param string|null $context The field context (defauts to ContentService::$fieldContext)
     * @return bool Whether a field with that handle exists
     */
    public function doesFieldWithHandleExist(string $handle, string $context = null): bool
    {
        if ($context === null) {
            $context = Craft::$app->getContent()->fieldContext;
        }

        if ($this->_allFieldHandlesByContext === null) {
            $this->_allFieldHandlesByContext = [];

            $results = (new Query())
                ->select(['handle', 'context'])
                ->from(['{{%fields}}'])
                ->all();

            foreach ($results as $result) {
                $this->_allFieldHandlesByContext[$result['context']][] = $result['handle'];
            }
        }

        return (isset($this->_allFieldHandlesByContext[$context]) && in_array($handle, $this->_allFieldHandlesByContext[$context], true));
    }

    /**
     * Returns all the fields in a given group.
     *
     * @param int $groupId The field group’s ID
     * @return FieldInterface[] The fields
     */
    public function getFieldsByGroupId(int $groupId): array
    {
        $results = $this->_createFieldQuery()
            ->where(['fields.groupId' => $groupId])
            ->all();

        $fields = [];

        foreach ($results as $result) {
            $fields[] = $this->createField($result);
        }

        return $fields;
    }

    /**
     * Returns all of the fields used by a given element type.
     *
     * @param string $elementType
     * @return FieldInterface[] The fields
     */
    public function getFieldsByElementType(string $elementType): array
    {
        $results = $this->_createFieldQuery()
            ->innerJoin('{{%fieldlayoutfields}} flf', '[[flf.fieldId]] = [[fields.id]]')
            ->innerJoin('{{%fieldlayouts}} fl', '[[fl.id]] = [[flf.layoutId]]')
            ->where([
                'fl.type' => $elementType,
                'fl.dateDeleted' => null,
            ])
            ->all();

        $fields = [];

        foreach ($results as $result) {
            $fields[] = $this->createField($result);
        }

        return $fields;
    }

    /**
     * Saves a field.
     *
     * @param FieldInterface $field The Field to be saved
     * @param bool $runValidation Whether the field should be validated
     * @return bool Whether the field was saved successfully
     * @throws \Throwable if reasons
     */
    public function saveField(FieldInterface $field, bool $runValidation = true): bool
    {
        /** @var Field $field */
        $isNewField = $field->getIsNew();

        // Fire a 'beforeSaveField' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_FIELD)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_FIELD, new FieldEvent([
                'field' => $field,
                'isNew' => $isNewField,
            ]));
        }

        if (!$field->beforeSave($isNewField)) {
            return false;
        }

        if ($runValidation && !$field->validate()) {
            Craft::info('Field not saved due to validation error.', __METHOD__);
            return false;
        }

        // Clear the translation key format if not using a custom translation method
        if ($field->translationMethod !== Field::TRANSLATION_METHOD_CUSTOM) {
            $field->translationKeyFormat = null;
        }

        $projectConfig = Craft::$app->getProjectConfig();
        $configData = $field->createFieldConfig();

        if ($isNewField) {
            $uid = StringHelper::UUID();
        } else {
            $uid = $field->uid;
        }

        // Store with all the populated data for future reference.
        $this->_savingFields[$uid] = $field;

        // Only store field data in the project config for global context
        if ($field->context === 'global') {
            $configPath = self::CONFIG_FIELDS_KEY . '.' . $uid;
            $projectConfig->set($configPath, $configData);
        } else {
            // Otherwise just save it to the DB
            $this->saveFieldFromConfig($uid, $configData, $field->context);
        }

        if ($isNewField) {
            $field->id = Db::idByUid('{{%fields}}', $uid);
            $field->uid = $uid;
        } else {
            /** @var Field $newField */
            $newField = $this->getFieldById($field->id);
            $field->oldHandle = $newField->oldHandle;
        }

        return true;
    }

    /**
     * Handle field changes.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleChangedField(ConfigEvent $event)
    {
        if ($this->ignoreProjectConfigChanges) {
            return;
        }

        $data = $event->newValue;
        $fieldUid = $event->tokenMatches[0];
        $this->saveFieldFromConfig($fieldUid, $data, 'global');
    }

    /**
     * Deletes a field by its ID.
     *
     * @param int $fieldId The field’s ID
     * @return bool Whether the field was deleted successfully
     */
    public function deleteFieldById(int $fieldId): bool
    {
        $field = $this->getFieldById($fieldId);

        if (!$field) {
            return false;
        }

        return $this->deleteField($field);
    }

    /**
     * Deletes a field.
     *
     * @param FieldInterface $field The field
     * @return bool Whether the field was deleted successfully
     * @throws \Throwable if reasons
     */
    public function deleteField(FieldInterface $field): bool
    {
        /** @var Field $field */
        // Fire a 'beforeDeleteField' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_FIELD)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_FIELD, new FieldEvent([
                'field' => $field,
            ]));
        }

        if (!$field->beforeDelete()) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_FIELDS_KEY . '.' . $field->uid);

        // Fire an 'afterDeleteField' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_FIELD)) {
            $this->trigger(self::EVENT_AFTER_DELETE_FIELD, new FieldEvent([
                'field' => $field,
            ]));
        }

        return true;
    }

    /**
     * Handle a field getting deleted.
     *
     * @param ConfigEvent $event
     */
    public function handleDeletedField(ConfigEvent $event)
    {
        if ($this->ignoreProjectConfigChanges) {
            return;
        }

        $fieldUid = $event->tokenMatches[0];
        $fieldRecord = $this->_getFieldRecord($fieldUid);

        if (!$fieldRecord->id) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // De we need to delete the content column?
            $contentTable = Craft::$app->getContent()->contentTable;
            $fieldColumnPrefix = Craft::$app->getContent()->fieldColumnPrefix;

            if (Craft::$app->getDb()->columnExists($contentTable, $fieldColumnPrefix . $fieldRecord->handle)) {
                Craft::$app->getDb()->createCommand()
                    ->dropColumn($contentTable, $fieldColumnPrefix . $fieldRecord->handle)
                    ->execute();
            }

            // Delete the row in fields
            Craft::$app->getDb()->createCommand()
                ->delete('{{%fields}}', ['id' => $fieldRecord->id])
                ->execute();

            $fieldRecord->afterDelete();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Clear caches
        unset(
            $this->_fieldsById[$fieldRecord->id],
            $this->_fieldsByContextAndHandle[$fieldRecord->context][$fieldRecord->handle],
            $this->_allFieldsInContext[$fieldRecord->context],
            $this->_fieldsWithContent[$fieldRecord->context]
        );

        if (isset($this->_allFieldHandlesByContext[$fieldRecord->context])) {
            ArrayHelper::removeValue($this->_allFieldHandlesByContext[$fieldRecord->context], $fieldRecord->handle);
        }

        // Update the field version
        $this->updateFieldVersion();
    }

    /**
     * Refreshes the internal field cache.
     *
     * This should be called whenever a field is updated or deleted directly in
     * the database, rather than going through this service.
     */
    public function refreshFields()
    {
        $this->_fieldRecordsById = null;
        $this->_fieldsById = null;
        $this->_allFieldHandlesByContext = null;
        $this->_allFieldsInContext = null;
        $this->_fieldsByContextAndHandle = null;
        $this->_fieldsWithContent = null;
        $this->updateFieldVersion();
    }

    // Layouts
    // -------------------------------------------------------------------------

    /**
     * Returns a field layout by its ID.
     *
     * @param int $layoutId The field layout’s ID
     * @return FieldLayout|null The field layout, or null if it doesn’t exist
     */
    public function getLayoutById(int $layoutId)
    {
        if ($this->_layoutsById !== null && array_key_exists($layoutId, $this->_layoutsById)) {
            return $this->_layoutsById[$layoutId];
        }

        $result = $this->_createLayoutQuery()
            ->andWhere(['id' => $layoutId])
            ->one();

        return $this->_layoutsById[$layoutId] = $result ? new FieldLayout($result) : null;
    }

    /**
     * Returns a field layout by its associated element type.
     *
     * @param string $type The associated element type
     * @return FieldLayout The field layout
     */
    public function getLayoutByType(string $type): FieldLayout
    {
        if ($this->_layoutsByType !== null && array_key_exists($type, $this->_layoutsByType)) {
            return $this->_layoutsByType[$type];
        }

        $result = $this->_createLayoutQuery()
            ->andWhere(['type' => $type])
            ->one();

        if (!$result) {
            return $this->_layoutsByType[$type] = new FieldLayout();
        }

        $id = $result['id'];
        if (!isset($this->_layoutsById[$id])) {
            $this->_layoutsById[$id] = new FieldLayout($result);
        }

        return $this->_layoutsByType[$type] = $this->_layoutsById[$id];
    }

    /**
     * Returns a layout's tabs by its ID.
     *
     * @param int $layoutId The field layout’s ID
     * @return FieldLayoutTab[] The field layout’s tabs
     */
    public function getLayoutTabsById(int $layoutId): array
    {
        $tabs = $this->_createLayoutTabQuery()
            ->where(['layoutId' => $layoutId])
            ->all();

        foreach ($tabs as $key => $value) {
            $tabs[$key] = new FieldLayoutTab($value);
        }

        return $tabs;
    }

    /**
     * Returns the field IDs grouped by layout IDs, for a given set of layout IDs.
     *
     * @param int[] $layoutIds The field layout IDs
     * @return array
     */
    public function getFieldIdsByLayoutIds(array $layoutIds): array
    {
        $results = (new Query())
            ->select(['flf.layoutId', 'fields.id'])
            ->from(['{{%fields}} fields'])
            ->innerJoin('{{%fieldlayoutfields}} flf', '[[flf.fieldId]] = [[fields.id]]')
            ->where(['flf.layoutId' => $layoutIds])
            ->all();

        $fieldIdsByLayoutId = [];
        foreach ($results as $result) {
            $fieldIdsByLayoutId[$result['layoutId']][] = $result['id'];
        }

        return $fieldIdsByLayoutId;
    }

    /**
     * Returns the fields in a field layout, identified by its ID.
     *
     * @param int $layoutId The field layout’s ID
     * @return FieldInterface[] The fields
     */
    public function getFieldsByLayoutId(int $layoutId): array
    {
        $fields = [];

        $results = $this->_createFieldQuery()
            ->addSelect([
                'flf.layoutId',
                'flf.tabId',
                'flf.required',
                'flf.sortOrder',
            ])
            ->innerJoin('{{%fieldlayoutfields}} flf', '[[flf.fieldId]] = [[fields.id]]')
            ->innerJoin('{{%fieldlayouttabs}} flt', '[[flt.id]] = [[flf.tabId]]')
            ->where(['flf.layoutId' => $layoutId])
            ->orderBy(['flt.sortOrder' => SORT_ASC, 'flf.sortOrder' => SORT_ASC])
            ->all();

        foreach ($results as $result) {
            $fields[] = $this->createField($result);
        }

        return $fields;
    }

    /**
     * Assembles a field layout from post data.
     *
     * @param string|null $namespace The namespace that the form data was posted in, if any
     * @return FieldLayout The field layout
     */
    public function assembleLayoutFromPost(string $namespace = null): FieldLayout
    {
        $paramPrefix = ($namespace ? rtrim($namespace, '.') . '.' : '');
        $request = Craft::$app->getRequest();

        $postedFieldLayout = $request->getBodyParam($paramPrefix . 'fieldLayout', []);
        $requiredFields = $request->getBodyParam($paramPrefix . 'requiredFields', []);

        $fieldLayout = $this->assembleLayout($postedFieldLayout, $requiredFields);
        $fieldLayout->id = $request->getBodyParam($paramPrefix . 'fieldLayoutId');

        return $fieldLayout;
    }

    /**
     * Assembles a field layout.
     *
     * @param array $postedFieldLayout The post data for the field layout
     * @param array $requiredFields The field IDs that should be marked as required in the field layout
     * @return FieldLayout The field layout
     */
    public function assembleLayout(array $postedFieldLayout, array $requiredFields = []): FieldLayout
    {
        $tabs = [];
        $fields = [];

        $tabSortOrder = 0;

        // Get all the fields
        $allFieldIds = [];

        foreach ($postedFieldLayout as $fieldIds) {
            foreach ($fieldIds as $fieldId) {
                $allFieldIds[] = $fieldId;
            }
        }

        if (!empty($allFieldIds)) {
            $allFieldsById = [];

            $results = $this->_createFieldQuery()
                ->where(['id' => $allFieldIds])
                ->all();

            foreach ($results as $result) {
                $allFieldsById[$result['id']] = $this->createField($result);
            }
        }

        foreach ($postedFieldLayout as $tabName => $fieldIds) {
            $tabFields = [];
            $tabSortOrder++;

            foreach ($fieldIds as $fieldSortOrder => $fieldId) {
                if (!isset($allFieldsById[$fieldId])) {
                    continue;
                }

                $field = $allFieldsById[$fieldId];
                $field->required = in_array($fieldId, $requiredFields, false);
                $field->sortOrder = ($fieldSortOrder + 1);

                $fields[] = $field;
                $tabFields[] = $field;
            }

            $tab = new FieldLayoutTab();
            $tab->name = urldecode($tabName);
            $tab->sortOrder = $tabSortOrder;
            $tab->setFields($tabFields);

            $tabs[] = $tab;
        }

        $layout = new FieldLayout();
        $layout->setTabs($tabs);
        $layout->setFields($fields);

        return $layout;
    }

    /**
     * Saves a field layout.
     *
     * @param FieldLayout $layout The field layout
     * @param bool $runValidation Whether the layout should be validated
     * @return bool Whether the field layout was saved successfully
     * @throws Exception if $layout->id is set to an invalid layout ID
     */
    public function saveLayout(FieldLayout $layout, bool $runValidation = true): bool
    {
        $isNewLayout = !$layout->id;

        // Make sure the tabs/fields are memoized on the layout
        foreach ($layout->getTabs() as $tab) {
            $tab->getFields();
        }

        // Fire a 'beforeSaveFieldLayout' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_FIELD_LAYOUT)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_FIELD_LAYOUT, new FieldLayoutEvent([
                'layout' => $layout,
                'isNew' => $isNewLayout,
            ]));
        }

        if ($runValidation && !$layout->validate()) {
            Craft::info('Field layout not saved due to validation error.', __METHOD__);
            return false;
        }

        if (!$isNewLayout) {
            // Delete the old tabs/fields
            Craft::$app->getDb()->createCommand()
                ->delete('{{%fieldlayouttabs}}', ['layoutId' => $layout->id])
                ->execute();

            // Get the current layout
            if (($layoutRecord = FieldLayoutRecord::findOne($layout->id)) === null) {
                throw new Exception('Invalid field layout ID: ' . $layout->id);
            }
        } else {
            $layoutRecord = new FieldLayoutRecord();
        }

        // Save it
        $layoutRecord->type = $layout->type;

        if (!$isNewLayout) {
            $layoutRecord->id = $layout->id;
            $layoutRecord->uid = $layout->uid ?? Db::uidById('{{%fieldlayouts}}', $layout->id);
        }

        $layoutRecord->save(false);

        if ($isNewLayout) {
            $layout->id = $layoutRecord->id;
        }

        $layout->uid = $layoutRecord->uid;

        foreach ($layout->getTabs() as $tab) {
            $tabRecord = new FieldLayoutTabRecord();
            $tabRecord->layoutId = $layout->id;
            $tabRecord->name = $tab->name;
            $tabRecord->sortOrder = $tab->sortOrder;
            $tabRecord->save(false);
            $tab->id = $tabRecord->id;
            $tab->uid = $tabRecord->uid;

            foreach ($tab->getFields() as $field) {
                /** @var Field $field */
                $fieldRecord = new FieldLayoutFieldRecord();
                $fieldRecord->layoutId = $layout->id;
                $fieldRecord->tabId = $tab->id;
                $fieldRecord->fieldId = $field->id;
                $fieldRecord->required = (bool)$field->required;
                $fieldRecord->sortOrder = $field->sortOrder;
                $fieldRecord->save(false);
            }
        }

        // Fire an 'afterSaveFieldLayout' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_FIELD_LAYOUT)) {
            $this->trigger(self::EVENT_AFTER_SAVE_FIELD_LAYOUT, new FieldLayoutEvent([
                'layout' => $layout,
                'isNew' => $isNewLayout,
            ]));
        }

        return true;
    }

    /**
     * Deletes a field layout(s) by its ID.
     *
     * @param int|int[] $layoutId The field layout’s ID
     * @return bool Whether the field layout was deleted successfully
     */
    public function deleteLayoutById($layoutId): bool
    {
        if (!$layoutId) {
            return false;
        }

        foreach ((array)$layoutId as $thisLayoutId) {
            $layout = $this->getLayoutById($thisLayoutId);

            if ($layout) {
                $this->deleteLayout($layout);
            }
        }

        return true;
    }

    /**
     * Deletes a field layout.
     *
     * @param FieldLayout $layout The field layout
     * @return bool Whether the field layout was deleted successfully
     */
    public function deleteLayout(FieldLayout $layout): bool
    {
        // Fire a 'beforeDeleteFieldLayout' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_FIELD_LAYOUT)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_FIELD_LAYOUT, new FieldLayoutEvent([
                'layout' => $layout
            ]));
        }

        Craft::$app->getDb()->createCommand()
            ->softDelete('{{%fieldlayouts}}', ['id' => $layout->id])
            ->execute();

        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_FIELD_LAYOUT)) {
            $this->trigger(self::EVENT_AFTER_DELETE_FIELD_LAYOUT, new FieldLayoutEvent([
                'layout' => $layout
            ]));
        }

        return true;
    }

    /**
     * Deletes field layouts associated with a given element type.
     *
     * @param string $type The element type
     * @return bool Whether the field layouts were deleted successfully
     */
    public function deleteLayoutsByType(string $type): bool
    {
        $affectedRows = Craft::$app->getDb()->createCommand()
            ->softDelete('{{%fieldlayouts}}', ['type' => $type])
            ->execute();

        return (bool)$affectedRows;
    }

    /**
     * Restores a field layout by its ID.
     *
     * @param int $id The field layout’s ID
     * @return bool Whether the layout was restored successfully
     */
    public function restoreLayoutById(int $id): bool
    {
        $affectedRows = Craft::$app->getDb()->createCommand()
            ->restore('{{%fieldlayouts}}', ['id' => $id])
            ->execute();

        return (bool)$affectedRows;
    }

    /**
     * Sets a new field version, so the ContentBehavior and ElementQueryBehavior classes
     * will get regenerated on the next request.
     */
    public function updateFieldVersion()
    {
        // Make sure that ContentBehavior and ElementQueryBehavior have already been loaded,
        // so the field version change won't be detected until the next request
        class_exists(ContentBehavior::class);
        class_exists(ElementQueryBehavior::class);

        $info = Craft::$app->getInfo();
        $info->fieldVersion = StringHelper::randomString(12);
        Craft::$app->saveInfo($info);
    }

    /**
     * Stores a field in the database.
     *
     * @param string $fieldUid
     * @param array $data
     * @param string $context
     */
    public function saveFieldFromConfig(string $fieldUid, array $data, string $context)
    {
        $groupUid = $data['fieldGroup'];

        // Ensure we have the field group in place first
        if ($groupUid) {
            Craft::$app->getProjectConfig()->processConfigChanges(self::CONFIG_FIELDGROUP_KEY . '.' . $groupUid);
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $fieldRecord = $this->_getFieldRecord($fieldUid);
            $groupRecord = $this->_getGroupRecord($groupUid);
            $isNewField = $fieldRecord->isNewRecord;
            $fieldtype = $data['type'];

            // Create/alter the content table column
            $contentTable = Craft::$app->getContent()->contentTable;
            $oldColumnName = $this->oldFieldColumnPrefix . $fieldRecord->getOldHandle();
            $newColumnName = Craft::$app->getContent()->fieldColumnPrefix . $data['handle'];

            if ($fieldtype::hasContentColumn()) {
                $columnType = $data['contentColumnType'];

                // Make sure we're working with the latest data in the case of a renamed field.
                Craft::$app->getDb()->schema->refresh();

                if (Craft::$app->getDb()->columnExists($contentTable, $oldColumnName)) {
                    Craft::$app->getDb()->createCommand()
                        ->alterColumn($contentTable, $oldColumnName, $columnType)
                        ->execute();
                    if ($oldColumnName !== $newColumnName) {
                        Craft::$app->getDb()->createCommand()
                            ->renameColumn($contentTable, $oldColumnName, $newColumnName)
                            ->execute();
                    }
                } else if (Craft::$app->getDb()->columnExists($contentTable, $newColumnName)) {
                    Craft::$app->getDb()->createCommand()
                        ->alterColumn($contentTable, $newColumnName, $columnType)
                        ->execute();
                } else {
                    Craft::$app->getDb()->createCommand()
                        ->addColumn($contentTable, $newColumnName, $columnType)
                        ->execute();
                }
            } else {
                // Did the old field have a column we need to remove?
                if (
                    !$isNewField &&
                    $fieldRecord->getOldHandle() &&
                    Craft::$app->getDb()->columnExists($contentTable, $oldColumnName)
                ) {
                    Craft::$app->getDb()->createCommand()
                        ->dropColumn($contentTable, $oldColumnName)
                        ->execute();
                }
            }

            // Clear the translation key format if not using a custom translation method
            if ($data['translationMethod'] !== Field::TRANSLATION_METHOD_CUSTOM) {
                $data['translationKeyFormat'] = null;
            }

            $fieldRecord->uid = $fieldUid;
            $fieldRecord->groupId = $groupRecord->id;
            $fieldRecord->name = $data['name'];
            $fieldRecord->handle = $data['handle'];
            $fieldRecord->context = $context;
            $fieldRecord->instructions = $data['instructions'];
            $fieldRecord->searchable = $data['searchable'] ?? false;
            $fieldRecord->translationMethod = $data['translationMethod'];
            $fieldRecord->translationKeyFormat = $data['translationKeyFormat'];
            $fieldRecord->type = $data['type'];
            $fieldRecord->settings = $data['settings'] ?? null;

            $fieldRecord->save(false);

            $transaction->commit();

            // Update the field version
            $this->updateFieldVersion();

            // Tell the current ContentBehavior class about the field
            ContentBehavior::$fieldHandles[$fieldRecord->handle] = true;
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        // Clear caches
        $this->refreshFields();

        // For CP save requests, make sure we have all the custom data already saved on the object.
        /** @var Field $field */
        $field = $this->_savingFields[$fieldUid] ?? $this->getFieldById($fieldRecord->id);

        if (!$isNewField) {
            // Save the old field handle on the model in case the field type needs to do something with it.
            $field->oldHandle = $fieldRecord->getOldHandle();
        }

        $field->afterSave($isNewField);

        // Fire an 'afterSaveField' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_FIELD)) {
            $this->trigger(self::EVENT_AFTER_SAVE_FIELD, new FieldEvent([
                'field' => $field,
                'isNew' => $isNewField,
            ]));
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a Query object prepped for retrieving groups.
     *
     * @return Query
     */
    private function _createGroupQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'name',
                'uid',
            ])
            ->from(['{{%fieldgroups}}'])
            ->orderBy(['name' => SORT_ASC]);
    }

    /**
     * Returns a Query object prepped for retrieving fields.
     *
     * @return Query
     */
    private function _createFieldQuery(): Query
    {
        return (new Query())
            ->select([
                'fields.id',
                'fields.dateCreated',
                'fields.dateUpdated',
                'fields.groupId',
                'fields.name',
                'fields.handle',
                'fields.context',
                'fields.instructions',
                'fields.searchable',
                'fields.translationMethod',
                'fields.translationKeyFormat',
                'fields.type',
                'fields.settings',
                'fields.uid'
            ])
            ->from(['{{%fields}} fields'])
            ->orderBy(['fields.name' => SORT_ASC, 'fields.handle' => SORT_ASC]);
    }

    /**
     * Returns a Query object prepped for retrieving layouts.
     *
     * @return Query
     */
    private function _createLayoutQuery(): Query
    {
        return (new Query)
            ->select([
                'id',
                'type',
                'uid'
            ])
            ->from(['{{%fieldlayouts}}'])
            ->where(['dateDeleted' => null]);
    }

    /**
     * Returns a Query object prepped for retrieving layout tabs.
     *
     * @return Query
     */
    private function _createLayoutTabQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'layoutId',
                'name',
                'sortOrder',
                'uid'
            ])
            ->from(['{{%fieldlayouttabs}}'])
            ->orderBy(['sortOrder' => SORT_ASC]);
    }

    /**
     * Gets a field group record or creates a new one.
     *
     * @param mixed $criteria ID or UID of the field group.
     * @return FieldGroupRecord
     */
    private function _getGroupRecord($criteria): FieldGroupRecord
    {
        if (is_numeric($criteria)) {
            $groupRecord = FieldGroupRecord::findOne($criteria);
        } else if (\is_string($criteria)) {
            $groupRecord = FieldGroupRecord::findOne(['uid' => $criteria]);
        }

        return $groupRecord ?? new FieldGroupRecord();
    }

    /**
     * Returns a field record for a given UID
     *
     * @param string $uid
     * @return FieldRecord
     * @throws FieldNotFoundException if $field->id is invalid
     */
    private function _getFieldRecord(string $uid): FieldRecord
    {
        return FieldRecord::findOne(['uid' => $uid]) ?? new FieldRecord();
    }
}
