<?php
/**
 * Netzarbeiter
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this Module to
 * newer versions in the future.
 *
 * @category   Netzarbeiter
 * @package    Netzarbeiter_GroupsCatalog2
 * @copyright  Copyright (c) 2012 Vinai Kopp http://netzarbeiter.com
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 
abstract class Netzarbeiter_GroupsCatalog2_Model_Resource_Indexer_Abstract extends Mage_Index_Model_Resource_Abstract
{
	/**
	 * Initialize an array with store and group default visibility settings for this indexers entity
	 *
	 * array(
	 * 	 storeId1 => array(
	 *     group1-id,
	 *     group2-id,
	 *   ),
	 *   ...
	 * )
	 *
	 * Only groups that are allowed to see this indexers entities are included in the list.
	 *
	 * @var array $_storeDefaults
	 */
	protected $_storeDefaults = array();

	/**
	 * Array of frontend store ids
	 *
	 * @var array $_frontendStoreIds
	 */
	protected $_frontendStoreIds = array();

	/**
	 * Module helper instance
	 *
	 * @var Netzarbeiter_GroupsCatalog2_Helper_Data
	 */
	protected $_helper;

	/**
	 * Return the entity type code for this indexers entity
	 *
	 * @abstract
	 * @return string
	 */
	abstract protected function _getEntityTypeCode();

	/**
	 * Initialize indexer
	 */
	public function __construct()
	{
		parent::__construct();
		$this->_initStores();
		$this->_helper = Mage::helper('netzarbeiter_groupscatalog2');
	}

	/**
	 * Return the module data helper
	 *
	 * @return Netzarbeiter_GroupsCatalog2_Helper_Data
	 */
	protected function _helper()
	{
		return $this->_helper;
	}

	/**
	 * Initialize $_storeDefaults and $_frontendStoreIds array
	 * 
	 * @return void
	 */
	protected function _initStores()
	{
		foreach (Mage::app()->getStores() as $store)
		{
			$this->_storeDefaults[$store->getId()] = array();
		}
		$this->_frontendStoreIds = array_keys($this->_storeDefaults);
	}

	protected function _getProfilerName()
	{
		return 'Netzarbeiter_GroupsCatalog2::' . $this->_getEntityTypeCode();
	}

	/**
	 * Return the ids of the customer groups that may see this indexers entity
	 *
	 * @param int|string|Mage_Core_Model_Store $store
	 * @return array
	 */
	protected function _getStoreDefaultGroups($store)
	{
		$store = Mage::app()->getStore($store);
		if (! array_key_exists($store->getId(), $this->_storeDefaults))
		{
			$this->_storeDefaults[$store->getId()] = $this->_helper()->getEntityVisibleDefaultGroupIds($this->_getEntityTypeCode(), $store);
		}
		return $this->_storeDefaults[$store->getId()];
	}

	/**
	 * Return the groupscatalog index table name for this indexers entity
	 *
	 * @return string
	 */
	protected function _getIndexTable()
	{
		$table = $this->_helper()->getIndexTableByEntityType($this->_getEntityTypeCode());
		return $this->getTable($table);
	}

	/**
	 * Handle reindex all calls
	 *
	 * @return void
	 */
	public function reindexAll()
	{
		$this->_reindexEntity();
	}

	/**
	 * Update or rebuild the index.
	 *
	 * @param Mage_Index_Model_Event $event
	 * @return void
	 */
	protected function _reindexEntity($event = null)
	{
		Varien_Profiler::start($this->_getProfilerName() . '::reindexEntity');
		$entityType = Mage::getSingleton('eav/config')->getEntityType($this->_getEntityTypeCode());
		$attribute = Mage::getSingleton('eav/config')->getAttribute(
			$this->_getEntityTypeCode(), Netzarbeiter_GroupsCatalog2_Helper_Data::HIDE_GROUPS_ATTRIBUTE
		);
		$select = $this->_getReadAdapter()->select()
			->from(array('e' => $this->getTable($entityType->getEntityTable())), array('entity_id' => 'e.entity_id'))
			->joinLeft(
				array('a' => $attribute->getBackendTable()),
				$this->_getReadAdapter()->quoteInto('e.entity_id=a.entity_id AND a.attribute_id = ?', $attribute->getId()),
				array('group_ids' => 'value', 'store_id' => 'store_id')
			)
			->order('e.entity_id ASC')
			->order('a.store_id ASC');

		
		if (is_null($event))
		{
			$this->_getWriteAdapter()->truncateTable($this->_getIndexTable());
		}
		else
		{
			$entityIds = $event->getData('entity_ids');
			$select->where('e.entity_id IN (?)', $entityIds);
			$this->_getWriteAdapter()->delete($this->_getIndexTable(), array('entity_id IN (?)' => $entityIds));
		}
		$result = $this->_getReadAdapter()->fetchAll($select);
		$this->_insertIndexRecords($result);
		Varien_Profiler::stop($this->_getProfilerName() . '::reindexEntity');
	}

	/**
	 * Create the new index records for the indexer entity
	 *
	 * @param array $result
	 * @return void
	 */
	protected function _insertIndexRecords(array &$result)
	{
		Varien_Profiler::start($this->_getProfilerName() . '::reindexEntity::insert');
		$entityId = null;
		$data = $storesHandled = $entityDefaultGroups = array();
		foreach ($result as $row)
		{
			$this->_prepareRow($row);

			// A new entity is being handled
			if ($entityId !== $row['entity_id'])
			{
				// Add missing store id records to the insert data array for the previous entity id
				// That is why we skip this condition on the first iteration.
				// We need to do this last because then $storesHandled is set completely for the $entityId
				if (null !== $entityId)
				{
					$this->_addMissingStoreRecords($data, $entityId, $entityDefaultGroups, $storesHandled);
				}

				// Set new entity as default
				$entityId = $row['entity_id'];
				// Set default groups for new entity (store id 0 is the first one for each entity in $result)
				$entityDefaultGroups = $row['group_ids'];
				// Reset stores handled for new entity to empty list
				$storesHandled = array();
				// We don't need an index entry for store id 0, simply use it as the default
				continue;
			}

			// Add index record for each group id
			foreach ($row['group_ids'] as $groupId)
			{
				$data[] = array('entity_id' => $row['entity_id'], 'group_id' => $groupId, 'store_id' => $row['store_id']);
				$storesHandled[] = $row['store_id'];
			}

			// Insert 1000 records at a time
			if (count($data) >= 1000)
			{
				$this->_getWriteAdapter()->insertMultiple($this->_getIndexTable(), $data);
				$data = array();
			}
		}

		// The last iterations over $result will probably not have hit the >= 1000 mark, so we still need to insert

		// Add missing store id records to the insert data array for the last $entityId
		$this->_addMissingStoreRecords($data, $entityId, $entityDefaultGroups, $storesHandled);

		// Insert missing index records
		if (count($data) > 0)
		{
			$this->_getWriteAdapter()->insertMultiple($this->_getIndexTable(), $data);
		}
		Varien_Profiler::stop($this->_getProfilerName() . '::reindexEntity::insert');
	}

	/**
	 * Prepare the record read from the database for the further indexing
	 *
	 * @param array $row
	 * @return void
	 */
	protected function _prepareRow(array &$row)
	{
		// Entities that don't have a value for the groupscatalog attribute
		if (null === $row['group_ids'])
		{
			$row['group_ids'] = Netzarbeiter_GroupsCatalog2_Helper_Data::USE_DEFAULT;
			$row['store_id'] = Mage::app()->getStore(Mage_Core_Model_Store::ADMIN_CODE)->getId();
		}
		
		if (Netzarbeiter_GroupsCatalog2_Helper_Data::USE_DEFAULT == $row['group_ids'])
		{
			// Use store default ids if that is selected for the entity
			$row['group_ids'] = $this->_getStoreDefaultGroups($row['store_id']);
		}
		else
		{
			// We need the list of group ids as an array
			$row['group_ids'] = explode(',', $row['group_ids']);

			// Apply the hide/show configuration settings
			$row['group_ids'] = $this->_helper()->applyConfigModeSettingByStore(
				$row['group_ids'],
				$this->_getEntityTypeCode(),
				$row['store_id']
			);
		}
	}

	/**
	 * Add unhandled store default index records
	 *
	 * @param array $data
	 * @param int $entityId
	 * @param array $entityDefaultGroups
	 * @param array $storesHandled
	 * @return void
	 */
	protected function _addMissingStoreRecords(array &$data, $entityId, array $entityDefaultGroups, array $storesHandled)
	{
		foreach (array_diff($this->_frontendStoreIds, $storesHandled) as $storeId)
		{
			foreach ($entityDefaultGroups as $groupId)
			{
				$data[] = array('entity_id' => $entityId, 'group_id' => $groupId, 'store_id' => $storeId);
			}
		}
	}
}
