<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christoph Clausen <christoph.clausen@unige.ch>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
$dataEntryFile = DOKU_PLUGIN.'data/syntax/table.php';
if(file_exists($dataEntryFile)){
	require_once $dataEntryFile;
} else {
	msg('datatemplate: Cannot find Data plugin.', -1);
	return;
}

/**
 * This inherits from the table syntax, because it's basically the
 * same, just different output
 */
class syntax_plugin_datatemplate_list extends syntax_plugin_data_table {

	/**
	 * Connect pattern to lexer
	 */
	function connectTo($mode) {
		$this->Lexer->addSpecialPattern('----+ *datatemplatelist(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_datatemplate_list');
	}

	function handle($match, $state, $pos, &$handler){
		// We want the parent to handle the parsing, but still accept
		// the "template" paramter. So we need to remove the corresponding
		// line from $match.
		$template = '';
		$lines = explode("\n",$match);
		foreach ( $lines as $num => $line ) {
			// ignore comments
			$line = preg_replace('/(?<![&\\\\])#.*$/','',$line);
			$line = str_replace('\\#','#',$line);
			$line = trim($line);
			if(empty($line)) continue;
			$line = preg_split('/\s*:\s*/',$line,2);
			if (strtolower($line[0]) == 'template') {
				$template = $line[1];
				unset($lines[$num]);
				break;
			}
		}
		$match = implode("\n", $lines);
		$data = parent::handle($match, $state, $pos, $handler);
		if(!empty($template)) {
			$data['template'] = $template;
		}
		return $data;
	}

	/**
	 * Create output
	 */
	function render($format, &$R, $data) {
		if(!array_key_exists('template', $data)) {
			// If keyword "template" not present, we will leave
			// the rendering to the parent class.
			static $msgout = true;
			if($msgout) {
				msg("datatemplatelist: no template specified, using standard table output.");
				$msgout = false;
			}
			parent::render($format, $R, $data);
			return;
		}
		if($format != 'xhtml') return false;
		if(is_null($data)) return false;
		$R->info['cache'] = false;

		$sqlite = $this->dthlp->_getDB();
		if(!$sqlite) return false;

		#dbg($data);
		$sql = $this->_buildSQL($data); // handles request params, too
		#dbg($sql);

		// run query
		$clist = array_keys($data['cols']);
		$res = $sqlite->query($sql);

		$cnt = 0;
		$rows = array();
		while ($row = sqlite_fetch_array($res, SQLITE_NUM)) {
			$rows[] = $row;
			$cnt++;
			if($data['limit'] && ($cnt == $data['limit'])) break; // keep an eye on the limit
		}

		if ($cnt === 0) {
			$this->nullList($data, $clist, $R);
			return true;
		}

		// The following code is taken more or less from the templater plugin.
		// We are not using the plugin directly, because we want to use the
		// data plugin's treatment of URLs. Hence, we are going to do the
		// substitutions after the parsing
		$wikipage = preg_split('/\#/u', $data['template'], 2);

		$this->_renderTemplate($wikipage, $data, $rows, $R);
		$R->doc .= $this->_postRender($data, $res);
		return true;
	}

	function _renderTemplate($wikipage, $data, $rows, $R) {
		global $ID;
		resolve_pageid(getNS($ID), $wikipage[0], $exists);          // resolve shortcuts

		// check for permission
		if (auth_quickaclcheck($wikipage[0]) < 1) {
			// False means no permissions
			$R->doc .= '<div class="datatemplatelist"> No permissions to view the template </div>';
			return true;
		}
			
		// Now open the template, parse it and do the substitutions.
		// FIXME: This does not take circular dependencies into account!
		$file = wikiFN($wikipage[0]);
		if (!@file_exists($file)) {
			$R->doc .= '<div class="datatemplatelist">';
			$R->doc .= "Template {$wikipage[0]} not found. ";
			$R->internalLink($wikipage[0], '[Click here to create it]');
			$R->doc .= '</div>';
			return true;
		}

		// Construct replacement keys
		foreach ($data['headers'] as $num => $head) {
			$replacers['keys'][] = "@@" . $head . "@@";
			$replacers['raw_keys'][] = "@@!" . $head . "@@";
		}

		// Get the raw file, and parse it into its instructions. This could be cached... maybe.
		$rawFile = io_readfile($file);

		// embed the included page
		$R->doc .= "<div class=\"${data['classes']}\">";

		// We only want to call the parser once, so first do all the raw replacements and concatenate
		// the strings.
		$clist = array_keys($data['cols']);
		$raw = "";
		$i = 0;
		$replacers['vals_id'] = array();
		$replacers['keys_id'] = array();
		foreach ($rows as $row) {
			$replacers['keys_id'][$i] = array();
			foreach($replacers['keys'] as $key) {
				$replacers['keys_id'][$i][] = "@@[" . $i . "]" . substr($key,2);
			}
			$replacers['vals_id'][$i] = array();
			$replacers['raw_vals'] = array();
			foreach($row as $num => $cval) {
				$replacers['raw_vals'][] = trim($cval);
				$replacers['vals_id'][$i][] = $this->dthlp->_formatData($data['cols'][$clist[$num]], $cval, $R);
			}		
				
			// First do raw replacements
			$rawPart = str_replace($replacers['raw_keys'], $replacers['raw_vals'], $rawFile);
			// Now mark all remaining keys with an index
			$rawPart = str_replace($replacers['keys'], $replacers['keys_id'][$i], $rawPart);
			$raw .= $rawPart;
			$i++;
		}
		$instr = p_get_instructions($raw);

		// render the instructructions on the fly
		$text = p_render('xhtml', $instr, $info);
		// remove toc, section edit buttons and category tags
		$patterns = array('!<div class="toc">.*?(</div>\n</div>)!s',
                              '#<!-- SECTION \[(\d*-\d*)\] -->#e',
                              '!<div class="category">.*?</div>!s');
		$replace  = array('','','');
		$text = preg_replace($patterns,$replace,$text);
		// Do remaining replacements
		foreach($replacers['vals_id'] as $num => $vals) {
			$text = str_replace($replacers['keys_id'][$num], $vals, $text);
		}	
		$R->doc .= $text;
		$R->doc .= '</div>';
	}

	
	function _postRender($data, $res) {
		global $ID;
		// Add pagination controls
		if($data['limit']){
			$offset = (int) $_REQUEST['dataofs'];
            if($offset){
                $prev = $offset - $data['limit'];
                if($prev < 0) $prev = 0;

                // keep url params
                $params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
                $params['datasrt'] = $_REQUEST['datasrt'];
                $params['dataofs'] = $prev;

                $text .= '<a href="'.wl($ID,$params).
                              '" title="'.'Previous'.
                              '" class="prev">'.'&larr; Previous Page'.'</a>';                
            }

            $text .= '&nbsp;';

            if(sqlite_num_rows($res) > $data['limit']){
                $next = $offset + $data['limit'];

                // keep url params
                $params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
                $params['datasrt'] = $_REQUEST['datasrt'];
                $params['dataofs'] = $next;

                $text .= '<a href="'.wl($ID,$params).
                              '" title="'.'Next'.
                              '" class="next">'.'Next Page &rarr;'.'</a>';
            }
            if($text != '&nbsp;') 
            	return '<div class="prevnext">' . $text . '</div>';
            return "";
        }
	}
	
	function nullList($data, $clist, &$R) {
		$R->doc .= '<div class="templatelist">Nothing.</div>';
	}
}
