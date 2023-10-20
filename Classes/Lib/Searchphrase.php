<?php

namespace Tpwd\KeSearch\Lib;

/***************************************************************
 *  Copyright notice
 *  (c) 2012 Stefan Froemken
 *  All rights reserved
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Tpwd\KeSearch\Utility\AdditionalWordCharactersUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Plugin 'Faceted search - searchbox and filters' for the 'ke_search' extension.
 * @author    Stefan Froemken
 */
class Searchphrase
{
    /**
     * List of keys in piVars which should as tag based filters. See Partials/Filters/DateRange.html
     *
     * @var string[]
     */
    public const IGNORE_FOR_TAG_BUILDING = ['start', 'end'];

    /**
     * @var Pluginbase
     */
    public $pObj;

    /**
     * initializes this object
     * @param Pluginbase $pObj
     */
    public function initialize(Pluginbase $pObj)
    {
        $this->pObj = $pObj;
    }

    /**
     * build search phrase
     * @return array Array containing different elements which helps to build the search query
     */
    public function buildSearchPhrase()
    {
        $cleanSearchStringParts = [];
        $tagsAgainst = $this->buildTagsAgainst();
        $searchString = trim($this->pObj->piVars['sword'] ?? '');
        $searchString = $this->checkAgainstDefaultValue($searchString);
        $searchStringParts = $this->explodeSearchPhrase($searchString);
        foreach ($searchStringParts as $key => $part) {
            $part = trim($part, '\~+-*|"');
            if (!empty($part)) {
                $cleanSearchStringParts[$key] = $part;
            }
        }
        $searchStringParts = $this->explodeSearchPhrase($searchString, true);

        $searchArray = [
            'sword' => implode(' ', $cleanSearchStringParts), // f.e. hello karl-heinz +mueller
            'swords' => $cleanSearchStringParts, // f.e. Array: hello|karl|heinz|mueller
            'wordsAgainst' => implode(' ', $searchStringParts), // f.e. +hello* +karl* +heinz* +mueller*
            'tagsAgainst' => $tagsAgainst, // f.e. Array: +#category_213# +#color_123# +#city_42#
            'scoreAgainst' => implode(' ', $cleanSearchStringParts), // f.e. hello karl heinz mueller
        ];

        return $searchArray;
    }

    /**
     * checks if the entered searchstring is the default value
     * @param string $searchString
     * @return string Returns the searchstring or an empty string
     */
    public function checkAgainstDefaultValue($searchString)
    {
        $searchStringToLower = strtolower(trim($searchString));
        $defaultValueToLower = strtolower($this->pObj->pi_getLL('searchbox_default_value'));
        if ($searchStringToLower === $defaultValueToLower) {
            $searchString = '';
        }

        return $searchString;
    }

    /**
     * Explode search string and remove too short words. Additionaly add modifiers for in-word search and optionally
     * replace additional word characters.
     *
     * @param string $searchString
     * @param bool $replaceAdditionalWordCharacters
     * @return array
     */
    public function explodeSearchPhrase(string $searchString, bool $replaceAdditionalWordCharacters = false)
    {
        preg_match_all('/([+\-~<>])?\".*?"|[^ ]+/', $searchString, $matches);
        list($searchParts) = $matches;
        if (is_array($searchParts) && count($searchParts)) {
            foreach ($searchParts as $key => $word) {
                // check for boolean seperator
                if ($word === '|') {
                    continue;
                }

                // maybe we should check against the MySQL stoppword list:
                // Link: http://dev.mysql.com/doc/refman/5.0/en/fulltext-stopwords.html

                // don't check length if it is a phrase
                if (preg_match('/^([+\-~<>])?\"/', $word)) {
                    continue;
                }

                // prepare word for next check
                $word = trim($word, '+-~<>');

                // check for word length
                $searchWordLength = mb_strlen($word);
                if ($searchWordLength < $this->pObj->extConf['searchWordLength']) {
                    $this->pObj->hasTooShortWords = true;
                    unset($searchParts[$key]);
                }
            }

            // Replace additional word characters
            if ($replaceAdditionalWordCharacters) {
                foreach ($searchParts as $key => $word) {
                    $searchParts[$key] = AdditionalWordCharactersUtility::replaceAdditionalWordCharacters($word);
                }
            }

            foreach ($searchParts as $key => $word) {
                if ($word != '|') {
                    // Enable partial word search (default: on) and in-word-search (Sphinx-based or native).
                    // Partial word search is activated automatically if in-word-search is activated
                    if (
                        ($this->pObj->extConf['enablePartSearch'] ?? true)
                        ||
                        (ExtensionManagementUtility::isLoaded('ke_search_premium') && ($this->pObj->extConfPremium['enableSphinxSearch'] ?? false) && (int)($this->pObj->extConfPremium['enableInWordSearch'] ?? false))
                        ||
                        (ExtensionManagementUtility::isLoaded('ke_search_premium') && ($this->pObj->extConfPremium['enableNativeInWordSearch'] ?? false))
                    ) {
                        if ($this->pObj->extConfPremium['enableInWordSearch'] ?? false) {
                            $searchParts[$key] = '*' . trim($searchParts[$key], '*') . '*';
                        } else {
                            $searchParts[$key] = rtrim($searchParts[$key], '*') . '*';
                        }
                    }

                    // add + explicit to all search words to make the searchresults equal to sphinx search results
                    if ($this->pObj->extConf['enableExplicitAnd']) {
                        $searchParts[$key] = '+' . ltrim($searchParts[$key], '+');
                    }
                }
            }
            return array_values($searchParts);
        }
        return [];
    }

    /**
     * build tags against
     * @return array
     */
    public function buildTagsAgainst()
    {
        $tagsAgainst = [];
        $this->buildPreselectedTagsAgainst($tagsAgainst);
        $this->buildPiVarsTagsAgainst($tagsAgainst);

        return $tagsAgainst;
    }

    /**
     * add preselected filter options (preselected in the backend flexform)
     * @param array $tagsAgainst
     */
    public function buildPreselectedTagsAgainst(array &$tagsAgainst)
    {
        $tagChar = $this->pObj->extConf['prePostTagChar'];
        foreach ($this->pObj->preselectedFilter as $key => $filterTags) {
            // Add it only, if no other filter options of this filter has been selected in the frontend.
            // We ignore values with one character length here (e.g. "-"), those are coming from the routing
            // configuration and are necessary for the routing but should be ignored here.
            if (!isset($this->pObj->piVars['filter'][$key])
                || (is_string($this->pObj->piVars['filter'][$key]) && strlen($this->pObj->piVars['filter'][$key]) === 1)
            ) {
                if (!isset($tagsAgainst[$key])) {
                    $tagsAgainst[$key] = '';
                }
                // Quote the tags for use in database query
                $queryBuilder = Db::getQueryBuilder('tx_kesearch_index');
                foreach ($filterTags as $k => $v) {
                    $filterTags[$k] = $queryBuilder->quote($v);
                }
                // if we are in checkbox mode
                if (count($this->pObj->preselectedFilter[$key]) >= 2) {
                    $tagsAgainst[$key] .= ' "'
                        . $tagChar
                        . implode($tagChar . '" "' . $tagChar, $filterTags)
                        . $tagChar
                        . '"';
                } elseif (count($this->pObj->preselectedFilter[$key]) == 1) {
                    // if we are in select or list mode
                    $tagsAgainst[$key] .= ' +"' . $tagChar . array_shift($filterTags) . $tagChar . '"';
                }
            }
        }
    }

    /**
     * Creates the list of tags for which should be filtered from the given piVars. Ignores certain piVars, e. g.
     * those for the date filter, those are handled differently, Tpwd\KeSearch\Lib\DB::createQueryForDateRange().
     *
     * @param array $tagsAgainst
     */
    public function buildPiVarsTagsAgainst(array &$tagsAgainst)
    {
        // add filter options selected in the frontend
        $tagChar = $this->pObj->extConf['prePostTagChar'];
        if (is_array($this->pObj->piVars['filter'] ?? null)) {
            foreach ($this->pObj->piVars['filter'] as $key => $tag) {
                // If this->pObj->piVars['filter'][$key] is an array this means the filter
                // is a "checkbox" filter with multi-selection of the values.
                if (is_array($this->pObj->piVars['filter'][$key] ?? null)) {
                    foreach ($this->pObj->piVars['filter'][$key] as $subkey => $subtag) {
                        // Don't add the tag if it is already inserted by preselected filters
                        if (!empty($subtag)
                            && strstr($tagsAgainst[$key] ?? '', $subtag) === false
                            && !in_array($subkey, self::IGNORE_FOR_TAG_BUILDING)
                        ) {
                            if (!isset($tagsAgainst[$key])) {
                                $tagsAgainst[$key] = '';
                            }
                            // Don't add a "+", because we are here in checkbox mode. It's a OR.
                            $tagsAgainst[$key] .= ' "' . $tagChar . $subtag . $tagChar . '"';
                        }
                    }
                } else {
                    // Don't add the tag if it is already inserted by preselected filters
                    if (
                        !empty($tag)
                        && (strlen($tag) > 1)
                        && strstr($tagsAgainst[$key] ?? '', $tag) === false
                        && !in_array($key, self::IGNORE_FOR_TAG_BUILDING)
                    ) {
                        if (!isset($tagsAgainst[$key])) {
                            $tagsAgainst[$key] = '';
                        }
                        $tagsAgainst[$key] .= ' +"' . $tagChar . $tag . $tagChar . '"';
                    }
                }
            }
        }

        // hook for modifiying the tags to filter for
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyTagsAgainst'] ?? null)) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyTagsAgainst'] as $_classRef) {
                $_procObj = GeneralUtility::makeInstance($_classRef);
                $_procObj->modifyTagsAgainst($tagsAgainst, $this);
            }
        }
    }
}
