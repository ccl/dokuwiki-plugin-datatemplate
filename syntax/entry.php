<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christoph Clausen <christoph.clausen@gmail.com>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
$dataEntryFile = DOKU_PLUGIN.'data/syntax/entry.php';
if(file_exists($dataEntryFile)){
    require_once $dataEntryFile;
} else {
    msg('datatemplate: Cannot find Data plugin.', -1);
    return;
}

class syntax_plugin_datatemplate_entry extends syntax_plugin_data_entry {

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
	$this->Lexer->addSpecialPattern('----+ *datatemplateentry(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',
					$mode,'plugin_datatemplate_entry');
    }

    /**
     * Handle the match - parse the data
     */
    function handle($match, $state, $pos, &$handler){
	// The parser of the parent class should have nicely parsed all
	// parameters. We want to extract the template parameter and treat
	// it separately.

	// strip datatemplateentry to get right classes
        $match = preg_replace('/datatemplateentry/', '', $match, 1);
	$data = parent::handle($match, $state, $pos, $handler);
	if(array_key_exists('template',  $data['cols'])) {
	    unset($data['cols']['template']);
	    $data['template'] = $data['data']['template'];
	    unset($data['data']['template']);
	}
	return $data;
    }

    /**
     * Output the data in a table
     */
    function _showData($data,&$R){
	global $ID;
	$R->info['cache'] = false;
	if(!array_key_exists('template', $data)) {
	    // If keyword "template" not present, we can leave
	    // the rendering to the parent class.
	    parent::_showData($data, $R);
	    return;
	}

	// The following code is taken more or less from the templater plugin.
	// We are not using the plugin directly, because we want to use the
	// data plugin's treatment of URLs. Hence, we are going to do the
	// substitutions after the parsing
	$wikipage = preg_split('/\#/u', $data['template'], 2);

	resolve_pageid(getNS($ID), $wikipage[0], $exists);          // resolve shortcuts

	// check for permission
	if (auth_quickaclcheck($wikipage[0]) < 1) {
	    // False means no permissions
	    $R->doc .= '<div class="datatemplateentry"> No permissions to view the template </div>';
	    return true;
	}

	// Now open the template, parse it and do the substitutions.
	// FIXME: This does not take circular dependencies into account!
	$file = wikiFN($wikipage[0]);
	if (!@file_exists($file)) {
	    $R->doc .= '<div class="datatemplateentry">';
	    $R->doc .= "Template {$wikipage[0]} not found. ";
	    $R->internalLink($wikipage[0], '[Click here to create it]');
	    $R->doc .= '</div>';
	    return true;
	}

	// Get the raw file, and parse it into its instructions. This could be cached... maybe.
	$rawFile = io_readfile($file);

	// embed the included page
	$R->doc .= '<div class="datatemplateentry ' . $data['classes'] . '">';

	foreach($data['data'] as $key => $val){
	    if($val == '' || !count($val)) continue;
	    $type = $data['cols'][$key]['type'];
	    if (is_array($type)) $type = $type['type'];
	    switch ($type) {
	    case 'pageid':
		$type = 'title';
	    case 'wiki':
		$val = $ID . '|' . $val;
		break;
	    }
	    $replacers['keys'][] = "@@" . $key . "@@";
	    $replacers['raw_keys'][] = "@@!" . $key . "@@";
	    if(is_array($val)){
		$cnt = count($val);
		$ret = '';
		$ret_raw = '';
		for ($i=0; $i<$cnt; $i++){
		    $ret .= $this->dthlp->_formatData($data['cols'][$key], $val[$i],$R);
		    $ret_raw .= $val[$i];
		    if($i < $cnt - 1) {
			$ret .= '<span class="sep">, </span>';
			$ret_raw .= ', ';
		    }
		}
		$replacers['vals'][] = $ret;
		$replacers['raw_vals'][] = $ret_raw;
	    } else {
		$replacers['vals'][] = $this->dthlp->_formatData($data['cols'][$key], $val, $R);
		$replacers['raw_vals'][] = $val;
	    }
	}
	// First do raw replacements
	$raw = str_replace($replacers['raw_keys'], $replacers['raw_vals'], $rawFile);
	if(DEBUG) dbg("RAW TEXT: \n" . $raw);
	$instr = p_get_instructions($raw);

	// render the instructructions on the fly
	$text = p_render('xhtml', $instr, $info);

	// replace in rendered wiki
	if(DEBUG) dbg("RENDERED TEXT: \n" . $text);
	$text = str_replace($replacers['keys'], $replacers['vals'], $text);
	if(DEBUG) dbg("REPLACED TEXT: \n" . $text);

	// Remove unused placeholders
	if(DEBUG) {
	    //$matches = array();
	    $num = preg_match_all('/@@.*?@@/', $text, $matches);
	    dbg("$num Unused placeholders\n:" . print_r($matches, true));
	}
	$text = preg_replace('/@@.*?@@/', '', $text);


	$R->doc .= $text;
	$R->doc .= '</div>';
	return true;
    }

    /**
     * Save date to the database
     */
    function _saveData($data,$id,$title){
	global $ID;
	// We are overriding this function to modify the stored
	// page title, which should be generated using the template.

	// If certain conditions are not fulfilled, we cannot extract a title.
	// In that case just use the title that has been handed over.
	if(!array_key_exists('template', $data)) {
	    parent::_saveData($data, $id, $title);
	    return;
	}
	$wikipage = preg_split('/\#/u', $data['template'], 2);
	resolve_pageid(getNS($ID), $wikipage[0], $exists);

	if (auth_quickaclcheck($wikipage[0]) < 1) {
	    parent::_saveData($data, $id, $title);
	    return;
	}
	$file = wikiFN($wikipage[0]);
	if (!@file_exists($file)) {
	    parent::_saveData($data, $id, $title);
	    return;
	}
	$rawFile = io_readfile($file);

	// Do Raw replacements
	foreach($data['data'] as $key => $val){
	    $replacers['raw_keys'][] = "@@!" . $key . "@@";
	    if(is_array($val)){
		$cnt = count($val);
		$ret_raw = '';
		for ($i=0; $i<$cnt; $i++){
		    $ret_raw .= $val[$i];
		    if($i < $cnt - 1) $ret_raw .= ', ';
		}
		$replacers['raw_vals'][] = $ret_raw;
	    } else {
		$replacers['raw_vals'][] = $val;
	    }
	}
	$raw = str_replace($replacers['raw_keys'], $replacers['raw_vals'], $rawFile);
	$instr = p_get_instructions($raw);

	// Find first header
	foreach ($instr as $i) {
	    if ($i[0] == 'header') {
		// Header found. Do replacement if necessary.
		preg_match_all("/@@(.+?)@@/", $i[1][0], $matches);
		$title = $i[1][0];
		foreach ($matches[1] as $m) {
		    $title = str_replace("@@".$m."@@", $data['data'][$m], $title);
		}
		break;
	    }
	}
	parent::_saveData($data, $id, $title);
    }
}
/* Local Variables: */
/* c-basic-offset: 4 */
/* End: */
