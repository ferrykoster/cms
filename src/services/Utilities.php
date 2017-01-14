<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\services;

use Craft;
use craft\base\UtilityInterface;
use craft\events\RegisterComponentTypesEvent;
use craft\utilities\AssetIndexes;
use craft\utilities\ClearCaches;
use craft\utilities\DbBackup;
use craft\utilities\DeprecationErrors;
use craft\utilities\FindAndReplace;
use craft\utilities\PhpInfo;
use craft\utilities\SearchIndexes;
use craft\utilities\SystemReport;
use craft\utilities\Updates as UpdatesUtility;
use yii\base\Component;

/**
 * The Utilities service provides APIs for managing utilities.
 *
 * An instance of the Utilities service is globally accessible in Craft via [[Application::utilities `Craft::$app->getUtilities()`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Utilities extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event RegisterComponentTypesEvent The event that is triggered when registering utility types.
     */
    const EVENT_REGISTER_UTILITY_TYPES = 'registerUtilityTypes';

    // Public Methods
    // =========================================================================

    /**
     * Returns all available utility type classes.
     *
     * @return string[]
     */
    public function getAllUtilityTypes(): array
    {
        $utilityTypes = [
            UpdatesUtility::class,
            SystemReport::class,
            PhpInfo::class,
            DeprecationErrors::class,
        ];

        if (!empty(Craft::$app->getVolumes()->getAllVolumes())) {
            $utilityTypes[] = AssetIndexes::class;
        }

        $utilityTypes[] = ClearCaches::class;
        $utilityTypes[] = DbBackup::class;
        $utilityTypes[] = FindAndReplace::class;
        $utilityTypes[] = SearchIndexes::class;

        $event = new RegisterComponentTypesEvent([
            'types' => $utilityTypes
        ]);
        $this->trigger(self::EVENT_REGISTER_UTILITY_TYPES, $event);

        return $event->types;
    }

    /**
     * Returns all utility type classes that the user has permission to use.
     *
     * @return string[]
     */
    public function getAuthorizedUtilityTypes(): array
    {
        $utilityTypes = [];

        foreach ($this->getAllUtilityTypes() as $class) {
            if ($this->checkAuthorization($class)) {
                $utilityTypes[] = $class;
            }
        }

        return $utilityTypes;
    }

    /**
     * Returns whether the current user is authorized to use a given utility.
     *
     * @param string $class The utility class
     *
     * @return bool
     */
    public function checkAuthorization(string $class): bool
    {
        /** @var string|UtilityInterface $class */
        return Craft::$app->getUser()->checkPermission('utility:'.$class::id());
    }

    /**
     * Returns a utility class by its ID
     *
     * @param string $id
     *
     * @return string|null
     */
    public function getUtilityTypeById(string $id)
    {
        foreach ($this->getAllUtilityTypes() as $class) {
            /** @var UtilityInterface $class */
            if ($class::id() === $id) {
                return $class;
            }
        }

        return null;
    }
}
