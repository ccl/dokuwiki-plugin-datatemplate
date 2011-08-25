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
     * Generate wiki output from instructions
     */
    function _showData($data, &$R) {
	global $ID;
	$R->info['cache'] = false;
	$instr = $this->_getInstructions($data);
	if(!array_key_exists('template', $data)) {
	    // If keyword "template" not present, we can leave
	    // the rendering to the parent class.
	    parent::_showData($data, $R);
	    return;
	}
	// check for permission
	if (auth_quickaclcheck($wikipage[0]) < 1) {
	    // False means no permissions
	    $R->doc .= '<div class="datatemplateentry"> No permissions to view the template </div>';
	    return true;
	}

	$wikipage = preg_split('/\#/u', $data['template'], 2);
	resolve_pageid(getNS($ID), $wikipage[0], $exists);          // resolve shortcuts

	// Check if page exists at all.
	$file = wikiFN($wikipage[0]);
	if (!@file_exists($file)) {
	    $R->doc .= '<div class="datatemplateentry">';
	    $R->doc .= "Template {$wikipage[0]} not found. ";
	    $R->internalLink($wikipage[0], '[Click here to create it]');
	    $R->doc .= '</div>';
	    return true;
	}

	// embed the included page
	$R->doc .= '<div class="datatemplateentry ' . $data['classes'] . '">';

	// render the instructructions on the fly
	$text = p_render('xhtml', $instr, $info);

	$R->doc .= $text;
	$R->doc .= '</div>';

	return true;
    }


    /**
     * Read and process template file and return wiki instructions.
     */
    function _getInstructions($data){
	global $ID;
	if(!array_key_exists('template', $data)) {
	    // If keyword "template" not present, we can leave
	    // the rendering to the parent class.
	    return null;
	}

	// The following code is taken more or less from the templater plugin.
	// We are not using the plugin directly, because we want to use the
	// data plugin's treatment of URLs. Hence, we are going to do the
	// substitutions after the parsing
	$wikipage = preg_split('/\#/u', $data['template'], 2);

	resolve_pageid(getNS($ID), $wikipage[0], $exists);          // resolve shortcuts

	// Now open the template, parse it and do the substitutions.
	// FIXME: This does not take circular dependencies into account!
	$file = wikiFN($wikipage[0]);
	if (!@file_exists($file)) {
	    return null;
	}

	// Get the raw file, and parse it into its instructions. This could be cached... maybe.
	$rawFile = io_readfile($file);

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
		    $ret .= $this->_formatData($data['cols'][$key], $val[$i]);
		    $ret_raw .= $val[$i];
		    if($i < $cnt - 1) {
			$ret .= ', ';
			$ret_raw .= ', ';
		    }
		}
		$replacers['vals'][] = $ret;
		$replacers['raw_vals'][] = $ret_raw;
	    } else {
		$replacers['vals'][] = $this->_formatData($data['cols'][$key], $val);
		$replacers['raw_vals'][] = $val;
	    }
	}
	// First do raw replacements
	$raw = str_replace($replacers['raw_keys'], $replacers['raw_vals'], $rawFile);
	$raw = str_replace($replacers['keys'], $replacers['vals'], $raw);
	$raw = preg_replace('/@@.*?@@/', '', $raw);
	$instr = p_get_instructions($raw);

	return $instr;
    }

    /**
     * The data plugin defines this function in its helper class with the purpose
     * to output XHTML code for the different column types.
     *   We want to let the dokuwiki renderer generate the required output, such
     * that also metadata is handled correctly. Hence, we will try to translate
     * each column type to the corresponding dokuwiki syntax.
     */
    function _formatData($column, $value){
        global $conf;
        $vals = explode("\n",$value);
        $outs = array();
        foreach($vals as $val){
            $val = trim($val);
            if($val=='') continue;
            $type = $column['type'];
            if (is_array($type)) $type = $type['type'];
            switch($type){
                case 'page':
		    $outs[] = '[[' . $val. ']]';
                    break;
                case 'title':
                    list($id,$title) = explode('|',$val,2);
		    $outs[] = '[[' . $id . '|' . $title . ']]';
                    break;
                case 'nspage':
                    $val = ':'.$column['key'].":$val";
		    $outs[] = '[[' . $val . ']]';
                    break;
                case 'mail':
                    list($id,$title) = explode(' ',$val,2);
		    $outs[] = '[[' . $id . '|' . $title . ']]';
                    break;
                case 'url':
                    $outs[] = '[[' . $val . ']]';
		    break;
                case 'tag':
                    #FIXME not handled by datatemplate so far
                    $outs[] = '<a href="'.wl(str_replace('/',':',cleanID($column['key'])),array('dataflt'=>$column['key'].':'.$val )).
                              '" title="'.sprintf($this->getLang('tagfilter'),hsc($val)).
                              '" class="wikilink1">'.hsc($val).'</a>';
                    break;
                case 'wiki':
		    $outs[] = $data;
                    break;
                default:
                    //$val = $this->_addPrePostFixes($column['type'], $val);
                    if(substr($type,0,3) == 'img'){
                        $sz = (int) substr($type,3);
                        if(!$sz) $sz = 40;
                        $title = $column['key'].': '.basename(str_replace(':','/',$val));
			$outs[] = '{{' . $val . '}}';
		    }else{
                        $outs[] = $val;
                    }
            }
        }
        return join(', ',$outs);
    }

    /**
     * Save date to the database
     */
    function _saveData($data,$id,$title){
	global $ID;
	// We are overriding this function to modify the stored
	// page title, which possibly should be generated using the template.

	// If certain conditions are not fulfilled, we cannot extract a title.
	// In that case just use the title that has been handed over.
	if(!array_key_exists('template', $data) || $title ) {
	    parent::_saveData($data, $id, $title);
	    return;
	}

	$instr = $this->_getInstructions($data);
	$renderer =& p_get_renderer('metadata');

	// loop through the instructions
	foreach ($instr as $instruction){
	    // execute the callback against the renderer
	    if ($instruction[0] == 'header' && !$title) $title = $instruction[1][0];
	    call_user_func_array(array(&$renderer, $instruction[0]), $instruction[1]);
	}

	parent::_saveData($data, $id, $title);
    }
}
/* Local Variables: */
/* c-basic-offset: 4 */
/* End: */
