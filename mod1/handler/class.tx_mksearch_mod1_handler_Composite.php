<?php

/**
 * Backend Modul Index.
 *
 * @author Michael Wagner <dev@dmk-ebusiness.de>
 */
class tx_mksearch_mod1_handler_Composite extends tx_mksearch_mod1_handler_Base implements tx_rnbase_mod_IModHandler
{
    /**
     * Method to get a company searcher.
     *
     * @param tx_rnbase_mod_IModule $mod
     * @param array                 $options
     *
     * @return tx_mksearch_mod1_searcher_abstractBase
     */
    protected function getSearcher(tx_rnbase_mod_IModule $mod, &$options)
    {
        if (!isset($options['pid'])) {
            $options['pid'] = $mod->id;
        }

        return tx_rnbase::makeInstance('tx_mksearch_mod1_searcher_Composite', $mod, $options);
    }

    /**
     * Returns a unique ID for this handler. This is used to created the subpart in template.
     *
     * @return string
     */
    public function getSubID()
    {
        return 'Composite';
    }
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/mksearch/mod1/handler/class.tx_mksearch_mod1_handler_Composite.php']) {
    include_once $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/mksearch/mod1/handler/class.tx_mksearch_mod1_handler_Composite.php'];
}
