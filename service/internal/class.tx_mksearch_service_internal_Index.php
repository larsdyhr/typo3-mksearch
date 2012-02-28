<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 das Medienkombinat
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(t3lib_extMgm::extPath('rn_base') . 'class.tx_rnbase.php');
tx_rnbase::load('tx_mksearch_service_internal_Base');
tx_rnbase::load('tx_rnbase_util_Logger');


/**
 * Service for accessing index models from database
 */
class tx_mksearch_service_internal_Index extends tx_mksearch_service_internal_Base {
	/**
	 * Search class of this service
	 *
	 * @var string
	 */
	protected $searchClass = 'tx_mksearch_search_Index';
	/**
	 * Database table responsible for indexing queue management
	 *
	 * @var string
	 */
	private static $queueTable = 'tx_mksearch_queue';

	/**
	 * Search database for all configurated Indices
	 *
	 * @param 	tx_mksearch_model_internal_Composite 	$indexerconfig
	 * @return 	array[tx_mksearch_model_internal_Index]
	 */
	public function getByComposite(tx_mksearch_model_internal_Composite $composite) {
		$fields['INDXCMPMM.uid_foreign'][OP_EQ_INT] = $composite->getUid();
// 		$options['debug']=1;
		return $this->search($fields, $options);
	}

	/**
	 * Add a single database record to search index.
	 *
	 * @param string $tableName
	 * @param int $uid
	 * @param boolean $prefer
	 * @param string $resolver class name of record resolver
	 * @param array $data
	 * @return boolean true if record was successfully spooled
	 */
	public function indexRecord($tableName, $uid, $prefer=false, $resolver=false, $data=false) {
		if($resolver === false) {
			$resolver = tx_mksearch_util_Config::getResolverForDatabaseTable($tableName);
			$resolver = count($resolver) ? $resolver['className'] : '';
		}
		// dummy record bauen!
		$record = array(
				'recid' => $uid,
				'tablename' => $tableName,
				'resolver' => $resolver,
				'data' => is_array($data) ? serialize($data) : $data,
			);
		// Indizierung starten
		self::executeQueueData(array($record));
	}

	/**
	 * Builds the record for insert
	 *
	 * @param 	string 		$tableName
	 * @param 	int 		$uid
	 * @param 	boolean 	$prefer
	 * @param 	string 		$resolver class name of record resolver
	 * @param 	array 		$data
	 * @param 	array 		$options
	 * @return 	mixed		array: (cr_date,prefer,recid,tablename,data,resolver) | false: if allredy exists
	 */
	private function buildRecordForIndex($tableName, $uid, $prefer=false, $resolver=false, $data=false, array $options = array()){
		$checkExisting = isset($options['checkExisting']) ? $options['checkExisting'] : true;
		if($resolver === false) {
			$resolver = tx_mksearch_util_Config::getResolverForDatabaseTable($tableName);
			$resolver = count($resolver) ? $resolver['className'] : '';
		}
		if($checkExisting) {
			tx_rnbase::load('tx_rnbase_util_DB');
			$options = array();
			$options['where'] = 'recid=\''.$uid . '\' AND tablename=\''.$tableName .'\' AND deleted=0';
			$options['enablefieldsoff'] = 1;
			$ret = tx_rnbase_util_DB::doSelect('uid', self::$queueTable, $options);
			if(count($ret)) return false; // Item schon in queue
		}

		tx_rnbase::load('tx_rnbase_util_Dates');
		// achtung: die reihenfolge ist wichtig für addRecordsToIndex
		$record = array(
				'cr_date'	=> tx_rnbase_util_Dates::datetime_tstamp2mysql($GLOBALS['EXEC_TIME']),
				'prefer' 	=> $prefer ? 1 : 0,
				'recid'		=> $uid,
				'tablename'	=> $tableName,
				'data'		=> $data!==false ? (is_array($data) ? serialize($data) : $data) : '',
				'resolver'	=> !empty($resolver) ? $resolver : '',
			);
		return $record;
	}

	/**
	 * Add a single database record to search index.
	 *
	 * @param 	string 		$tableName
	 * @param 	int 		$uid
	 * @param 	boolean 	$prefer
	 * @param 	string 		$resolver class name of record resolver
	 * @param 	array 		$data
	 * @param 	array 		$options
	 * @return 	boolean 	true if record was successfully spooled
	 */
	public function addRecordToIndex($tableName, $uid, $prefer=false, $resolver=false, $data=false, array $options = array()) {
		$record  = $this->buildRecordForIndex($tableName, $uid, $prefer, $resolver, $data);

		if(!is_array($record)){
			return;
		}

		$qid = tx_rnbase_util_DB::doInsert(self::$queueTable, $record);
		if(tx_rnbase_util_Logger::isDebugEnabled()) {
			tx_rnbase_util_Logger::debug('New record to be indexed added to queue.', 'mksearch', array('queue-id'=>$qid, 'tablename' => $tableName, 'recid' => $uid));
		}
		return $qid > 0;
	}

	/**
	 * Add single database records to search index.
	 *
	 * @param 	array 		$records array('tablename' => 'required', 'uid' => 'required', 'preferer' => 'optional', 'resolver' => 'optional', 'data' => 'optional');
	 * @param 	array 		$options
	 * @return 	boolean 	true if record was successfully spooled
	 */
	public function addRecordsToIndex(array $records, array $options = array()) {
		if(empty($records)){
			return true;
		}
		$sqlValues = array();
		$count = 0;
		// build records
		foreach($records as &$record) {
			$tableName = $record['tablename'];
			$uid = $record['uid'];
			// only if table and uid exists
			if(empty($tableName) || empty($uid)) {
				continue;
			}
			$prefer = isset($record['preferer']) ? $record['preferer'] : false;
			$resolver = isset($record['resolver']) ? $record['resolver'] : false;
			$data = isset($record['data']) ? $record['data'] : false;
			// build record, inclusiv existing check
			$record = $this->buildRecordForIndex($tableName, $uid, $prefer, $resolver, $data, $options);

			// ready to insert
			if(is_array($record)) {
				// quote and escape values
				$record = $GLOBALS['TYPO3_DB']->fullQuoteArray($record, self::$queueTable);
				// build the query part
				$sqlValues[] = '(' . implode(',', $record) . ')';
				// insert max. 500 items
				$count++;
				if($count >= 500) {
					// do import
					$this->doInsertRecords($sqlValues);
					// reset
					$count = 0;
					$sqlValues = array();
				}
			}
		}
		$this->doInsertRecords($sqlValues);
		return $GLOBALS['TYPO3_DB']->sql_insert_id() > 0;
	}
	/**
	 * Does the insert.
	 * @param 	$sqlValues 	$sqlValues
	 * @return 	boolean
	 */
	private function doInsertRecords(array $sqlValues){
		$insert = 'INSERT INTO '.self::$queueTable.'(cr_date,prefer,recid,tablename,data,resolver)';

		// no inserts found
		if(empty($sqlValues)) {
			return true;
		}

		// build query string
		$sqlQuery = $insert." VALUES \r\n".implode(", \r\n",$sqlValues).';';
		tx_rnbase_util_DB::doQuery($sqlQuery);
		if(tx_rnbase_util_Logger::isDebugEnabled()) {
			tx_rnbase_util_Logger::debug('New records to be indexed added to queue.', 'mksearch', array('sqlQuery' => $sqlQuery));
		}
		return $GLOBALS['TYPO3_DB']->sql_insert_id() > 0;
	}

	public function countItemsInQueue($tablename='') {
		$options = array();
		$options['count']=1;
		$options['where'] = 'deleted=0';
		if(strcmp($tablename,'')) {
			$fullQuoted = $GLOBALS['TYPO3_DB']->fullQuoteStr($tablename, self::$queueTable);
			$options['where'] .= ($tablename ? ' AND tablename='.$fullQuoted : '');
		}
		$options['enablefieldsoff']=1;

		$data = tx_rnbase_util_DB::doSelect('count(*) As cnt', self::$queueTable, $options);
		return $data[0]['cnt'];
	}
	/**
	 * Trigger indexing from indexing queue
	 *
	 * @param 	array 	$config
	 * 						no: 	Number of items to be indexed
	 * 						pid: 	trigger only records for this pageid
	 * @return 	array	indexed tables array(array('tablename' => 'count'))
	 */
	public function triggerQueueIndexing($config=array()) {
		if (!is_array($config)){
			$limit = $config;
			$config = array();
		} else {
			$limit = $config['limit'] ? $config['limit'] : 100;
		}
		$options = array();
		$options['orderby'] = 'prefer desc, cr_date asc, uid asc';
		$options['limit'] = $limit;
		$options['where'] = 'deleted=0';
		$options['enablefieldsoff'] = 1;

		$data = tx_rnbase_util_DB::doSelect('*', self::$queueTable, $options);

		// Nothing found in queue? Stop
		if (empty($data)) return 0;

		// Trigger update for the found items
		self::executeQueueData($data, $config);

		$uids = array();
		$rows = array();
		foreach ($data As $queue) {
			$uids[] = $queue['uid'];
			// daten sammeln
			$rows[$queue['tablename']][] = $queue['recid'];
		}
		if($GLOBALS['TYPO3_CONF_VARS']['MKSEARCH_testmode'] != 1) {
			$ret = tx_rnbase_util_DB::doUpdate(self::$queueTable, 'uid IN ('. implode(',', $uids) . ')', array('deleted' => 1));
			tx_rnbase_util_Logger::info('Indexing run finished with '.$ret. ' items executed.', $extKey , array('data'=>$data));
		}
		else
			tx_rnbase_util_Logger::info('Indexing run finished in test mode. Queue not deleted!', $extKey , array('data'=>$data));
		return $rows;
	}
	/**
	 * Abarbeitung der Indizierungs-Queue
	 * @param 	array $data records aus der Tabelle tx_mksearch_queue
	 * @param 	array 	$config
	 * 						pid: 	trigger only records for this pageid
	 * @return 	void
	 */
	private function executeQueueData($data, $config) {
		$rootline = 0;
		// alle indexer fragen oder nur von der aktuellen pid?
		if($config['pid']) {
			$indices = $this->getByPageId($config['pid']);
		}
		else {
			$indices = $this->findAll();
		}

		tx_rnbase_util_Logger::debug('[INDEXQUEUE] Found '.count($indices) . ' indices for update', 'mksearch');

		try {
			// Loop through all active indices, collecting all configurations
			foreach ($indices as $index) {
				/* @var $index tx_mksearch_model_internal_Index */
				// Wir lesen die rootpage des indexers aus.
				tx_rnbase::load('tx_mksearch_service_indexer_core_Config');
				$rootpage = tx_mksearch_service_indexer_core_Config::getSiteRootPage(
								// die rootpage des indexers nutzen
								$index->record['rootpage'] ? $index->record['rootpage'] :
								(
									// wurde eine pid übergeben?
									$config['pid'] ? $config['pid'] :
									// fallback ist die pid
									$index->record['pid']
								)
					);

				tx_rnbase_util_Logger::debug('[INDEXQUEUE] Next index is '.$index->getTitle(), 'mksearch');
				// Container for all documents to be indexed / deleted
				$indexDocs = array();
				$searchEngine = tx_mksearch_util_ServiceRegistry::getSearchEngine($index);

				$indexConfig = $index->getIndexerOptions();
				// Ohne indexConfig kann nichts indiziert werden
				if(!$indexConfig) {
					tx_rnbase_util_Logger::notice('[INDEXQUEUE] No indexer config found! Re-check your settings in mksearch BE-Module!', 'mksearch',
						array('Index'=> $index->getTitle() . ' ('.$index->getUid().')', 'indexerClass' => get_class($index), 'indexdata' => $uids));
					continue; // Continue with next index
				}

				if(tx_rnbase_util_Logger::isDebugEnabled())
					tx_rnbase_util_Logger::debug('[INDEXQUEUE] Config for index '.$index->getTitle().' found.', 'mksearch', array($indexConfig));

				// Jetzt die Datensätze durchlaufen (Könnte vielleicht auch als äußere Schleife erfolgen...)
				foreach($data As $queueRecord) {
					// Zuerst laden wir den Resolver
					$resolverClazz = $queueRecord['resolver'] ? $queueRecord['resolver'] : 'tx_mksearch_util_ResolverT3DB';
					$resolver = tx_rnbase::makeInstance($resolverClazz);
					try {
						$dbRecords = $resolver->getRecords($queueRecord);
						foreach($dbRecords As $record) {
							// Diese Records müssen jetzt den jeweiligen Indexern übegeben werden.
							foreach ($this->getIndexersForTable($index, $queueRecord['tablename']) as $indexer) {
						//foreach (tx_mksearch_util_Config::getIndexersForTable($queueRecord['tablename']) as $indexer) {
								if(!$indexer) continue; // Invalid indexer
								// Collect all index documents
								list($extKey, $contentType) = $indexer->getContentType();
								//there can be more than one config for the current indexer
								//so we execute the indexer with each config that was found.
								//when one element (tt_content) is indexed by let's say tow indexer configs which
								//aim to the same index than you should take care that the element
								//isn't taken by both indexer configs as the doc of the element for
								//the first config will be overwritten by the second one
								foreach ($indexConfig[$extKey.'.'][$contentType.'.'] as $aConfigByContentType){

									// die rootpage dem indexer zur verfügung stellen wenn vorhanden
									if(!empty($rootpage)){
										$aConfigByContentType['rootpage'] = $rootpage;

										// Dem Indexer mitteilen, das dieser Record in Rootpage enthalten sein muss wenn
										// die rootpage größer als 0 ist.
										// Der Indexer muss sich darum kümmern, ob dieses Element indiziert werden soll.
										// @see tx_mksearch_indexer_Base::checkPageTreeIncludes

										// @todo das heißt sobald die rootpage konfiguriert ist,
										// werden alle Datensätze eines Indexs als valide betrachtet.
										// wenn bspw. nur einige Seiten indiziert werden sollen, müssten
										// alle übrigen in die exclude.pageTrees Option aufgenommen werden.
										// das ist insbesondere bei neu hinzukommenden Seiten nicht haltbar
										// da die Konfiguration fortlaufend angepasst werden müsste. Es sollte
										// besser eine zusätzliche Option im Index vorhanden sein, die erlaubt
										// die rootpage hinzuzufügen!!!
										if($rootpage['uid'] > 0){
											$aConfigByContentType['include.']['pageTrees.'][] = $rootpage['uid'];
										}
									}

									// indizieren!
									$doc = $indexer->prepareSearchData($queueRecord['tablename'], $record, $searchEngine->makeIndexDocInstance($extKey, $contentType), $aConfigByContentType);
									if($doc) {
										//add fixed_fields from indexer config if defined
										$doc = $this->addFixedFields($doc, $aConfigByContentType);

										try {
											$indexDocs[$doc->getPrimaryKey(true)] = $doc;
										}
										catch(Exception $e) {
											tx_rnbase_util_Logger::warn('[INDEXQUEUE] Invalid document returned from indexer.', 'mksearch', array('Indexer class' => get_class($indexer), 'record' => $record));
										}
									}
								}
							}
						}
					}
					catch(Exception $e) {
						tx_rnbase_util_Logger::warn('[INDEXQUEUE] Error processing queue item ' . $queueRecord['uid'], 'mksearch', array('Exception' => $e->getMessage(), 'Queue-Item'=> $queueRecord));
					}
				}
				// Finally, actually do the index update, if there is sth. to do:
				if (count($indexDocs)) {
					$searchEngine->openIndex($index, true);
					foreach ($indexDocs as $doc) {
						try {
							if($doc->getDeleted()) {
								$key = $doc->getPrimaryKey();
								$searchEngine->indexDeleteByContentUid($key['uid']->getValue(), $key['extKey']->getValue(), $key['contentType']->getValue());
							}
							else
								$searchEngine->indexUpdate($doc);

						}
						catch(Exception $e) {
							tx_rnbase_util_Logger::fatal('[INDEXQUEUE] Fatal error processing search document!', 'mksearch', array('Exception' => $e->getMessage(), 'document'=> $doc->__toString() , 'data'=> $doc->getData()));
						}
					}
					$searchEngine->commitIndex();
					//shall something be done after indexing?
					$searchEngine->postProcessIndexing($index);
					//that's it
					$searchEngine->closeIndex();
				}

			}
		}
		catch (Exception $e) {
			tx_rnbase_util_Logger::fatal('[INDEXQUEUE] Fatal error processing queue occured!', 'mksearch', array('Exception' => $e->getMessage(), 'Queue-Items'=> $data));
		}
		return 0;


//
//
//		$relevantIndexers = tx_mksearch_util_Config::getIndexersForDatabaseTables(array_keys($data));
//		foreach($cores As $core) {
//			$cred = self::getCredentialsFromString($core->getCredentialString());
//			$solrSrv = self::getSolrEngine($cred);
//			foreach($relevantIndexers As $indexerData) {
//				// Check if indexer is matches with core
//				if(!$srv->isIndexerDefined($core, $indexerData)) continue;
//
//				// Extract data relevant for the current indexer
//				$parameterData = array();
//				foreach (tx_mksearch_util_Config::getDatabaseTablesForIndexer($indexerData['extKey'], $indexerData['contentType']) as $tableName) {
//					if (array_key_exists($tableName, $data))
//						$parameterData[$tableName] = $data[$tableName];
//				}
//
//				$indexer = tx_mksearch_util_ServiceRegistry::getIndexerService($indexerData['extKey'], $indexerData['contentType']);
//				// @todo When moved to EXT:mksearch => Fetch options for this indexer
//				// Irgendwie kann das mit mehreren Tabellen nicht funktionieren!!
//				$options = array();
//				$indexer->prepare($options, $parameterData);
//
//				list($extKey, $contentType) = $indexer->getContentType();
//				do {
//					$e = null;
//					try {
//						$doc = $indexer->nextItem($solrSrv->makeIndexDocInstance($extKey, $contentType));
//						try {
//							if ($doc) {
//								$solrSrv->indexUpdate($doc);
//								if(tx_rnbase_util_Logger::isNoticeEnabled() && method_exists($doc, '__toString')) {
//									tx_rnbase_util_Logger::notice('tx_mkhoga_srv_Search->updateIndex(): item indexed: '.$extKey.'/'.$contentType.'.', 'tx_mkhoga', array('Doc-PK' => $doc->__toString()));
//								}
//							}
//							// In welchen Fällen bekommen wir kein Doc??
//						}
//						catch (Exception $e) {
//							if(tx_rnbase_util_Logger::isWarningEnabled()) {
//								if(!method_exists($doc, '__toString')) {
//									$data = array();
//									foreach ($doc->getPrimaryKey() as $key=>$value) {
//										$data[$key] = $value->getValue();
//									}
//								}
//								else
//									$data = $doc->__toString();
//								tx_rnbase_util_Logger::warn('tx_mkhoga_srv_Search->updateIndex() 1: Error on indexing item '.$extKey.'/'.$contentType.'.', 'tx_mkhoga', array('code' => $e->getCode(), 'msg' => $e->getMessage(), 'docid' => $data));
//							}
//						}
//					}
//					catch (Exception $e) {
//						if(tx_rnbase_util_Logger::isWarningEnabled())
//							tx_rnbase_util_Logger::warn('tx_mkhoga_srv_Search->updateIndex(): Error on indexing item '.$extKey.'/'.$contentType.'.', 'tx_mkhoga', array('code' => $e->getCode(), 'msg' => $e->getMessage()));
//					}
//				} while ($doc !== null || (!empty($e)));
//			}
//			$solrSrv->commitIndex();
//		}
//		return;

	}

	/**
	 * Adds fixed fields which are defined in the indexer config
	 * if none are defined we have nothing to do
	 *
	 * @param tx_mksearch_interface_IndexerDocument $indexDoc
	 * @param array $options
	 *
	 * @return tx_mksearch_interface_IndexerDocument
	 */
	protected function addFixedFields(tx_mksearch_interface_IndexerDocument $indexDoc, $options) {
		$aFixedFields = $options['fixedFields.'];
		//without config there is nothing to do
		if(empty($aFixedFields)) return $indexDoc;

		foreach ($aFixedFields as $sFixedFieldKey => $mFixedFieldValue){
			//config is something like
			//site_area{
			//	0 = first
			//	1 = second
			//}
			if(is_array($mFixedFieldValue)){
				//if we have an array we have to delete the
				//trailing dot of the key name because this
				//seems senseless to add
				$sFixedFieldKey = substr($sFixedFieldKey, 0, strlen($sFixedFieldKey) -1);
			}
			//else the config is something like
			//site_area = first
			$indexDoc->addField($sFixedFieldKey, $mFixedFieldValue);
		}
		return $indexDoc;
	}

	/**
	 * Returns all indexers for an index and a specific tablename
	 * @param tx_mksearch_model_internal_Index $index
	 * @param string $tablename
	 */
	private function getIndexersForTable($index, $tablename) {
		$ret = array();
		$indexers = tx_mksearch_util_Config::getIndexersForTable($tablename);
		foreach($indexers As $indexer) {
			if($this->isIndexerDefined($index, $indexer))
				$ret[] = $indexer;
		}
		return $ret;
	}

	/**
	 * Clear indexing queue for the given table
	 *
	 * @param string	$table
	 * @param array		$options
	 */
	public static function clearIndexingQueueForTable($table) {
		global $GLOBALS;
		$fullQuoted = $GLOBALS['TYPO3_DB']->fullQuoteStr($table, self::$queueTable);
		return tx_rnbase_util_DB::doDelete(self::$queueTable, 'tablename=' . $fullQuoted);
	}

	/**
	 * Reset indexing queue for the given table
	 *
	 * Old entries are deleted before all (valid) entries of the
	 * given table name are inserted.
	 *
	 * @param string	$table
	 * @param array		$options
	 */
	public static function resetIndexingQueueForTable($table, array $options) {
		global $GLOBALS;
		self::clearIndexingQueueForTable($table);

		$resolver = tx_mksearch_util_Config::getResolverForDatabaseTable($table);
		$resolver = count($resolver) ? $resolver['className'] : '';

		$fullQuoted = $GLOBALS['TYPO3_DB']->fullQuoteStr($table, self::$queueTable);
		$uidName = isset($options['uidcol']) ? $options['uidcol'] : 'uid';
		$from = isset($options['from']) ? $options['from'] : $table;
		$where = isset($options['where']) ? ' WHERE ' . $options['where'] : '';

		$query = 'INSERT INTO ' . self::$queueTable . '(tablename, recid, resolver) ';
		$query .= 'SELECT DISTINCT ' . $fullQuoted . ', '. $uidName .
			', CONCAT(\''.$resolver.'\') FROM ' . $from . $where;

		if($options['debug'])
			t3lib_div::debug($query,'class.tx_mkhoga_srv_Search.php : ');
		$GLOBALS['TYPO3_DB']->sql_query($query);
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/service/internal/class.tx_mksearch_service_internal_Index.php']) {
  include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/service/internal/class.tx_mksearch_service_internal_Index.php']);
}