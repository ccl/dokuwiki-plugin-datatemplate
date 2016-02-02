<?php
/**
 * Filterform
 *
 * Inserts a form which allows searching and filtering in a list
 * generated by a datatable (data plugin) or a datatemplatelist
 * (datatemplate plugin).
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christoph Clausen <christoph.clausen@gmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_datatemplate_filterform extends DokuWiki_Syntax_Plugin {

    /**
     * Get the type of syntax this plugin defines.
     */
    function getType(){
        return 'substition';
    }

    /**
     * Define how this plugin is handled regarding paragraphs.
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 150;
    }


    /**
     * Connect lookup pattern to lexer.
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *filterform(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_datatemplate_filterform');
    }

    /**
     * Handler to prepare matched data for the rendering process.
     */
    function handle($match, $state, $pos, Doku_Handler $handler){
        $data = array();
        $lines = explode("\n",$match);
        foreach ( $lines as $num => $line ) {
            // ignore comments
            $line = preg_replace('/(?<![&\\\\])#.*$/','',$line);
            $line = str_replace('\\#','#',$line);
            $line = trim($line);
            if(empty($line)) continue;
            $line = preg_split('/\s*:\s*/',$line,2);
            if(strtolower($line[0]) == 'fields') {
                $data['fields'] = preg_split("/[\s,]+/", $line[1]);
            }
        }
        return $data;
    }

    /**
     * Handle the actual output creation.
     */
    function render($mode, Doku_Renderer $R, $data) {
        if($mode == 'xhtml'){
            /** @var $R Doku_Renderer_xhtml */
            $R->info['cache'] = false;
            if (isset($_POST['filterform']) && checkSecurityToken()) {
                $new_flt = '';
                if(!empty($_POST['contains'])) {
                    $new_flt = $_POST['field'] . '*~' . $_POST['contains'];
                    if(!isset($_REQUEST['dataflt'])){
                        $flt = array();
                    } elseif (!is_array($_REQUEST['dataflt'])){
                        $flt = (array) $_REQUEST['dataflt'];
                    } else {
                        $flt = $_REQUEST['dataflt'];
                    }

                    if(!empty($new_flt)) $flt[] = $new_flt;
                    $_REQUEST['dataflt'] = $flt;
                }
            }
            $R->doc .= $this->_htmlform($data);
            return true;
        }
        return false;
    }

    function _htmlform($data){
        global $ID;

        $form = new Doku_Form(array('class' => 'filterform_plugin', 'action' => wl($ID)));
        $form->addHidden('filterform', $ID);
        $form->addElement(form_openfieldset(array('_legend' => 'Search/Filter', 'class' => 'filterform')));
        $form->addElement(form_makeMenuField('field', $data['fields'], '', '', '', 'cell menu'));
        $form->addElement(form_makeTextField('contains', '', '', '', 'cell text'));
        if(count($_REQUEST['dataflt']) > 0) {
            $form->addElement('<div class="group">Previous Filters:</div>');
            foreach($_REQUEST['dataflt'] as $num=>$flt) {
                list($key, $value) = explode('*~', $flt);
                $value = trim($value, '*');
                $txt = '<i>' . $key . ':</i> ' . $value;
                $form->addElement(form_checkboxField(array('_text' => $txt, 'name' => 'dataflt[]','value' => $flt,
                                                           'checked'=>'true', '_class' => 'row')));
            }
        }
        $form->addElement(form_makeButton('submit', '', 'Submit'));
        $form->endFieldset();

        return $form->getForm();
    }
}