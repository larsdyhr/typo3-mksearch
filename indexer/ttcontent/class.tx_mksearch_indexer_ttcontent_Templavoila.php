<?php
/**
 * 	@package tx_mksearch
 *  @subpackage tx_mksearch_indexer
 *  @author Hannes Bochmann <hannes.bochmann@das-medienkombinat.de>
 *
 *  Copyright notice
 *
 *  (c) 2011 Hannes Bochmann <hannes.bochmann@das-medienkombinat.de>
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
 */

/**
 * benötigte Klassen einbinden
 */
require_once(t3lib_extMgm::extPath('rn_base') . 'class.tx_rnbase.php');
tx_rnbase::load('tx_mksearch_indexer_ttcontent_Normal');

/**
 * takes care of tt_content with templavoila support.
 * 
 * @todo wenn templavoila installiert ist, sollten allen elemente, die in einem tv
 * container liegen in die queue gelegt werden. beim indizieren muss dann noch geprüft
 * werden ob das element parents hat und diese nicht hidden sind.
 * @author Hannes Bochmann <hannes.bochmann@das-medienkombinat.de>
 */
class tx_mksearch_indexer_ttcontent_Templavoila extends tx_mksearch_indexer_ttcontent_Normal {
	/**
	 * The references (pids) of this element. if templavoila is
	 * not installed it contains only the pid of the element it self
	 * @var array
	 */
	protected $aReferences = array();

	/**
	 * The indexable references (pids) of this element. These references
	 * are taken to check if the element has to be deleted.
	 * @var array
	 */
	protected $aIndexableReferences = array();

	/**
	 * Sets the index doc to deleted if neccessary
	 * @param tx_rnbase_model_base $oModel
	 * @param tx_mksearch_interface_IndexerDocument $oIndexDoc
	 * @return bool
	 */
	protected function hasDocToBeDeleted(tx_rnbase_model_base $oModel, tx_mksearch_interface_IndexerDocument $oIndexDoc, $aOptions = array()) {
		//if we got not a single reference, even not the element itself, it should be deleted
		if (empty($this->aIndexableReferences)) {
			return true;
		}

		//now we have to check if at least one reference remains that has not to be deleted.
		$aStillIndexableReferences = array();
		foreach($this->aIndexableReferences as $iPid) {
			//set the pid
			$oModel->record['pid'] = $iPid;
			
			if (!parent::hasDocToBeDeleted($oModel, $oIndexDoc, $aOptions))
				$aStillIndexableReferences[$iPid] = $iPid;//set value as key to avoid doubles
		}

		//any valid references left?
		if(empty($aStillIndexableReferences)){
			return true;
		}else//finally set the pid of the first reference to our element
			//as we can not know which array index is the first we simply use
			//array_shift which returns the first off shifted element, our desired first one
			$oModel->record['pid'] = array_shift($aStillIndexableReferences);
		//else
		return false;
	}

	/**
	 * Returns all references (pids)  this element has. if templavoila is
	 * not installed we simply return the pid of the element
	 *
	 * @param tx_rnbase_model_base $oModel
	 * @return array
	 */
	private function getReferences(tx_rnbase_model_base $oModel) {
		//so we have to fetch all references
		//we just need to check this table for entries for this element
		$aSqlOptions = array(
			'where' => 'ref_table='.$GLOBALS['TYPO3_DB']->fullQuoteStr('tt_content','sys_refindex').
					' AND ref_uid='.intval($oModel->getUid()).
					' AND deleted=0',
			'enablefieldsoff' => true
		);
		$aFrom = array('sys_refindex', 'sys_refindex');
		$aRows = tx_rnbase_util_DB::doSelect('*', $aFrom, $aSqlOptions);

		//now we need to collect the pids of all references. either a
		//reference is a page than we simply use it's pid or the
		//reference is another tt_content. in the last case we take the pid of
		//this element
		$aReferences = array();
		if(!empty($aRows)){
			foreach ($aRows as $aRow){
				if($aRow['tablename'] == 'pages'){
					$aReferences[] = $aRow['recuid'];
				}
				elseif ($aRow['tablename'] == 'tt_content'){
					$aSqlOptions = array(
						'where' => 'tt_content.uid=' . $aRow['recuid'],
						'enablefieldsoff' => true//checks for being hidden/deleted are made later
					);
					$aFrom = array('tt_content', 'tt_content');
					$aNewRows = tx_rnbase_util_DB::doSelect('tt_content.pid', $aFrom, $aSqlOptions);
					$aReferences[] = $aNewRows[0]['pid'];
				}
			}
		}

		return $aReferences;
	}

	/**
	 * @see tx_mksearch_indexer_Base::isIndexableRecord()
	 */
	protected function isIndexableRecord(array $sourceRecord, array $options) {
		$this->aIndexableReferences = array();//init

		//we have to do the checks for all references and collect the indexable ones
		$return = false;
		if(!empty($this->aReferences)){
			foreach($this->aReferences as $iPid) {
				//set the pid
				$sourceRecord['pid'] = $iPid;

				if(	parent::isIndexableRecord($sourceRecord, $options) ) {
					$return = true;//as soon as we have a indexable reference we are fine
					//collect this pid as indexable
					$this->aIndexableReferences[$iPid] = $iPid;
				}
			}
		}else
			//without any references this element is indexable but will be
			//deleted later
			$return = true;

		return $return;
	}

	/**
	 * Returns the model to be indexed
	 *
	 * @param array $aRawData
	 *
	 * @return tx_mksearch_model_irfaq_Question
	 */
	protected function createModel(array $aRawData) {
		$oModel = parent::createModel($aRawData);
		//we need all references this element has for later checks
		$this->aReferences = $this->getReferences($oModel);

		return $oModel;
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/indexer/ttcontent/class.tx_mksearch_indexer_ttcontent_Templavoila.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/indexer/ttcontent/class.tx_mksearch_indexer_ttcontent_Templavoila.php']);
}