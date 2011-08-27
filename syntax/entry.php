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
     * Create output or save the data
     */
    function render($format, &$renderer, $data) {
        global $ID;
        switch ($format){
            case 'xhtml':
                $this->_showData($data,$renderer);
                return true;
            case 'metadata':
                $this->_saveData($data,$ID,$renderer);
                return true;
            case 'plugin_data_edit':
                $this->_editData($data, $renderer);
                return true;
            default:
                return false;
        }
    }

    /* Get template file name and check existance and access rights.
     * 
     * @returns  0 if the file does not exist
     *          -1 if no permission to read the file
     *          the file name otherwise
     */
    function _getFile($template) {
	global $ID;
	$wikipage = preg_split('/\#/u', $template, 2);

	resolve_pageid(getNS($ID), $wikipage[0], $exists);

	$file = wikiFN($wikipage[0]);

	if (!@file_exists($file)) return 0;

	if (auth_quickaclcheck($wikipage[0]) < 1) return -1;

	return $file;
    }


    /**
     * Generate wiki output from instructions
     */
    function _showData($data, &$R) {
	global $ID;
	$R->info['cache'] = false;

	if(!array_key_exists('template', $data)) {
	    // If keyword "template" not present, we can leave
	    // the rendering to the parent class.
	    parent::_showData($data, $R);
	    return;
	}
	$instr = $this->_getInstructions($data);
	// Treat possible errors first
	if($instr == 0) {
	    $R->doc .= '<div class="datatemplateentry">';
	    $R->doc .= "Template {$wikipage[0]} not found. ";
	    $R->internalLink($wikipage[0], '[Click here to create it]');
	    $R->doc .= '</div>';
	    return true;
	} elseif ($instr == -1) {
	    $R->doc .= '<div class="datatemplateentry"> No permissions to view the template </div>';
	    return true;
	}

	// embed the included page
	$R->doc .= '<div class="datatemplateentry ' . $data['classes'] . '">';

	// render the instructructions on the fly
	$text = p_render('xhtml', $instr, $info);

	// remove toc, section edit buttons and category tags
	$patterns = array('!<div class="toc">.*?(</div>\n</div>)!s',
			  '#<!-- EDIT.*? \[(\d*-\d*)\] -->#e',
			  '!<div class="category">.*?</div>!s');
	$replace  = array('','','');
	$text = preg_replace($patterns,$replace,$text);

	$R->doc .= $text;
	$R->doc .= '</div>';

	return true;
    }


    /**
     * Read and process template file and return wiki instructions. Passes through the return
     * value of _getFile if the file does not exist or cannot be accessed. If no template was specified,
     * return empty array.
     */
    function _getInstructions($data){
	global $ID;

	// Get the raw file, and parse it into its instructions. This could be cached... maybe.
	$file = $this->_getFile($data['template']);
	if(!is_string($file)) return $file;
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
     *
     * We are overriding this function to properly generate the metadata for
     * the parsed template, such that page title and table of contents are
     * correct.
     */
    function _saveData($data,$id,&$renderer){
	if(!array_key_exists('template', $data)) {
	    parent::_saveData($data, $id, $renderer->meta['title']);
	    return;
	}

	$file = $this->_getFile($data['template']);
	$instr = $this->_getInstructions($data);
	// If for some reason there are no instructions, don't do anything
	// (Maybe for cache handling one should hand the template file name to the
	// metadata, even though the file does not exist)
	if(!is_string($file)) parent::_saveData($data, $id, $renderer->meta['title']);

	// Remove document_start and document_end from instructions
	array_shift($instr);
	array_pop($instr);

	// loop through the instructions
	for($i = 0; $i < count($instr); $i++) {
	    call_user_func_array(array($renderer, $instr[$i][0]), $instr[$i][1]);
	}

	parent::_saveData($data, $id, $renderer->meta['title']);
    }
}
/* Local Variables: */
/* c-basic-offset: 4 */
/* End: */
