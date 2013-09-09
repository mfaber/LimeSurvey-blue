<?php

/* Creates a file containing responses in XML-format that can be imported directly to STATA versions 10 and above.
 * Use STATAs xmluse command to import. eg.: xmluse "\survey_844845_STATA.xml", doctype(dta)
 * In contrast to importing a plain CSV or xls-file, the data is fully labelled with variable- and value labels.
 * Date and time strings are converted to STATAs time format (milliseconds since 1960/01/01), so they can be directly used in calculations
 * Limitations: 
 *  STATA only supports strings up to 244 bytes.....long answers (ie. text fields) will be cut.
 *  STATA only supports attaching value labels to numerical values. So to achieve short answers (usually one or two digits) and
 *  have these properly labelled, one should use numerical answer-codes in LimeSurvey (1=Totally agree). 
 *  If non-numerical answer codes are used (A=Totally agree), then the complete answer text will be used as answer (eg.: 'Totally agree').
 */

class STATAxmlWriter extends Writer
{
    private $output;
    private $separator;
    private $hasOutputHeader;
    private $maxStringLength = 244; // max length of STATA string fields
    private $maxByte = 100; // max value of STATA byte var
    private $minByte = -127; // min value of STATA byte var
    private $maxInt = 32740; // max value of STATA int var
    private $minInt = -32767; // min value of STATA int var
    
    
    /**
     * The open filehandle
     */
    protected $handle = null;
    protected $customFieldmap = array();
    protected $customResponsemap = array();
    protected $headers = array();
    protected $headersSGQA = array();
    protected $aQIDnonumericalAnswers = array();
    
    function __construct()
    {
        $this->output          = '';
        $this->separator       = ',';
        $this->hasOutputHeader = false;
    }
    
    public function init(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions)
    {
        parent::init($survey, $sLanguageCode, $oOptions);
        if ($oOptions->output == 'display')
        {
            header("Content-Disposition: attachment; filename=survey_" . $survey->id . "_STATA.xml");
            header("Content-type: application/download; charset=US-ASCII");
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Pragma: public");
            $this->handle = fopen('php://output', 'w');
        }
        elseif ($oOptions->output == 'file')
        {
            $this->handle = fopen($this->filename, 'w');
        }
        $this->headersSGQA       = $oOptions->selectedColumns;
        $oOptions->headingFormat = 'code'; // Always use fieldcodes
        
        $this->customFieldmap = $this->createStataFieldmap($survey, $sLanguageCode, $oOptions);
    }
    
    
    protected function out($content)
    {
        fwrite($this->handle, $content . "\n");
    }
    
    
    /* Returns an array with vars, labels, survey info
     * For STATA-XML, we basically need:
     * Header: Number of Variables, Number of observations, SurveyTitle, Timestamp
     * Typelist: code, STATA_datatype
     * Varlist: code
     * fmtlist: code, STATA_format
     * Lbllist: code, setname of valuelabels
     * variable_labels: code, vardescription (question text)
     * Data: ObservationNumber(ID), code, value
     * Valuelabels: Setname, Answercode, Answer
     * 
     * Some things depending on the responses (eg. STATA data type and format, some reoding),
     * are done later in updateResponsemap()
     */
    function createStataFieldmap($survey, $sLanguage, $oOptions)
    {
        $clang = new Limesurvey_lang($sLanguage); // get survey language...eg. for value labels
        
        $yvalue = $oOptions->convertY ? $oOptions->yValue : '1'; // set value for Y if it is set in export settings (needed for correct value label)
        $nvalue = $oOptions->convertN ? $oOptions->nValue : '2'; // set value for N if it is set in export settings (needed for correct value label)
        
        //create fieldmap only with the columns (variables) selected
        $aFieldmap['questions'] = array_intersect_key($survey->fieldMap, array_flip($oOptions->selectedColumns));
        
        // add only questions and answers to the fieldmap that are relevant to the selected columns (variables)
        foreach ($aFieldmap['questions'] as $question)
        {
            $aUsedQIDs[] = $question['qid'];
        }
        $aFieldmap['answers'] = array_intersect_key($survey->answers, array_flip($aUsedQIDs));
        
        // add per-survey info
        $aFieldmap['info'] = $survey->info;
        
        // STATA only uses value labels on numerical variables. If the answer codes are not numerical we later replace them with the text-answer
        // here we go through the answers-array and check whether answer-codes are numerical. If they are not, we save the respective QIDs
        // so responses can later be set to full answer test of Question or SQ'
        foreach ($aFieldmap['answers'] as $qid => $aScale)
        {
            foreach ($aFieldmap['answers'][$qid] as $iScale => $aAnswers)
            {
                foreach ($aFieldmap['answers'][$qid][$iScale] as $iAnswercode => $aAnswer)
                {
                    if (!is_numeric($aAnswer['code']))
                    {
                        $this->aQIDnonumericalAnswers[$aAnswer['qid']] = true;
                    }
                }
            }
        }
        
        
        // go through the questions array and create/modify vars for STATA-output
        foreach ($aFieldmap['questions'] as $sSGQAkey => $aQuestion)
        {
            // STATA does not support attaching value labels to non-numerical values
            // We therefore set a flag in questions array for non-numerical answer codes. 
            // The respective codes are later recoded to contain the full answers
            if (array_key_exists($aQuestion['qid'], $this->aQIDnonumericalAnswers))
            {
                $aFieldmap['questions'][$sSGQAkey]['nonnumericanswercodes'] = true;
            }
            else
            {
                $aFieldmap['questions'][$sSGQAkey]['nonnumericanswercodes'] = false;
            }
            
            
            // create 'varname' from Question/Subquestiontitles
            $aQuestion['varname'] = viewHelper::getFieldCode($aFieldmap['questions'][$sSGQAkey]);
            
            //set field types for standard vars
            if ($aQuestion['varname'] == 'submitdate' || $aQuestion['varname'] == 'startdate' || $aQuestion['varname'] == 'datestamp')
            {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'D';
            }
            elseif ($aQuestion['varname'] == 'startlanguage')
            {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'S';
            }
            elseif ($aQuestion['varname'] == 'token')
            {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'S';
            }
            elseif ($aQuestion['varname'] == 'id')
            {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'N';
            }
            elseif ($aQuestion['varname'] == 'ipaddr')
            {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'S';
            }
            elseif ($aQuestion['varname'] == 'refurl')
            {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'S';
            }
            elseif ($aQuestion['varname'] == 'lastpage')
            {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'N';
            }
            
            
            //Rename the variables if original name is not STATA-compatible
            $aQuestion['varname'] = $this->STATAvarname($aQuestion['varname']);
            
            // create variable labels
            $aQuestion['varlabel'] = $aQuestion['question'];
            if (isset($aQuestion['scale']))
                $aQuestion['varlabel'] = "[{$aQuestion['scale']}] " . $aQuestion['varlabel'];
            if (isset($aQuestion['subquestion']))
                $aQuestion['varlabel'] = "[{$aQuestion['subquestion']}] " . $aQuestion['varlabel'];
            if (isset($aQuestion['subquestion2']))
                $aQuestion['varlabel'] = "[{$aQuestion['subquestion2']}] " . $aQuestion['varlabel'];
            if (isset($aQuestion['subquestion1']))
                $aQuestion['varlabel'] = "[{$aQuestion['subquestion1']}] " . $aQuestion['varlabel'];
            
            //write varlabel back to fieldmap
            $aFieldmap['questions'][$sSGQAkey]['varlabel'] = $aQuestion['varlabel'];
            
            //create value labels for question types with "fixed" answers (YES/NO etc.)
            if ((isset($aQuestion['other']) && $aQuestion['other'] == 'Y') || substr($aQuestion['fieldname'], -7) == 'comment')
            {
                $aFieldmap['questions'][$sSGQAkey]['commentother'] = true; //comment/other fields: create flag, so value labels are not attached (in close())
            }
            else
            {
                $aFieldmap['questions'][$sSGQAkey]['commentother'] = false;
                
                
                if ($aQuestion['type'] == 'M')
                {
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$yvalue] = array(
                        'code' => $yvalue,
                        'answer' => $clang->gT('Yes')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['0']     = array(
                        'code' => 0,
                        'answer' => $clang->gT('Not Selected')
                    );
                }
                elseif ($aQuestion['type'] == "P")
                {
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$yvalue] = array(
                        'code' => $yvalue,
                        'answer' => $clang->gT('Yes')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['0']     = array(
                        'code' => 0,
                        'answer' => $clang->gT('Not Selected')
                    );
                }
                elseif ($aQuestion['type'] == "G")
                {
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['0'] = array(
                        'code' => 'F',
                        'answer' => $clang->gT('Female')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['1'] = array(
                        'code' => 'M',
                        'answer' => $clang->gT('Male')
                    );
                }
                elseif ($aQuestion['type'] == "Y")
                {
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$yvalue] = array(
                        'code' => $yvalue,
                        'answer' => $clang->gT('Yes')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$nvalue] = array(
                        'code' => $nvalue,
                        'answer' => $clang->gT('No')
                    );
                }
                elseif ($aQuestion['type'] == "C")
                {
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['1'] = array(
                        'code' => 1,
                        'answer' => $clang->gT('Yes')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['0'] = array(
                        'code' => 2,
                        'answer' => $clang->gT('No')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['9'] = array(
                        'code' => 3,
                        'answer' => $clang->gT('Uncertain')
                    );
                }
                elseif ($aQuestion['type'] == "E")
                {
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['1']  = array(
                        'code' => 1,
                        'answer' => $clang->gT('Increase')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['0']  = array(
                        'code' => 2,
                        'answer' => $clang->gT('Same')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['-1'] = array(
                        'code' => 3,
                        'answer' => $clang->gT('Decrease')
                    );
                } 
            } // close: no-other/comment variable
        $aFieldmap['questions'][$sSGQAkey]['varname']=$aQuestion['varname'];     //write changes back to array
        } // close foreach question
        
        
        // clean up fieldmap (remove HTML tags, CR/LS, etc.)
        $aFieldmap = $this->stripArray($aFieldmap);
        return $aFieldmap;
    }
    
    
    /*  return a STATA-compatible variable name
     *    strips some special characters and fixes variable names starting with a number
     */
    protected function STATAvarname($sVarname)
    {
        if (!preg_match("/^([a-z]|[A-Z])+.*$/", $sVarname)) //var starting with a number?
        {
            $sVarname = "v" . $sVarname; //add a leading 'v'
        }
        $sVarname = str_replace(array(
            "-",
            ":",
            ";",
            "!",
            "[",
            "]",
            " "
        ), array(
            "_",
            "_dd_",
            "_dc_",
            "_excl_",
            "_",
            "",
            "_"
        ), $sVarname);
        return $sVarname;
    }
    
    
    /*  strip html tags, blanks and other stuff from array, flattens text
     */
    protected function stripArray($tobestripped)
    {
        Yii::app()->loadHelper('export');
        function clean(&$item)
        {
            $item = trim((htmlspecialchars_decode(stripTagsFull($item))));
        }
        array_walk_recursive($tobestripped, 'clean');
        return ($tobestripped);
    }
    
    
    /* Function is called for every response
     * Here we just use it to create arrays with variable names and data
     */
    protected function outputRecord($headers, $values, FormattingOptions $oOptions)
    {
        // function is called for every response to be exported....only write header once
        if (empty($this->headers))
        {
            $this->headers = $headers;
            foreach ($this->headers as $iKey => $sVarname)
            {
                $this->headers[$iKey] = $this->STATAvarname($sVarname);
            }
        }
        // gradually fill response array...
        $this->customResponsemap[] = $values;
    }
    
    /* 
    This function updates the fieldmap and recodes responses
    so output to XML in close() is a piece of cake...
    */
    protected function updateCustomresponsemap()
    {
        //create array that holds each values' data type
        foreach ($this->customResponsemap as $iRespId => $aResponses)
        {
            foreach ($aResponses as $iVarid => $response)
            {
                $response=trim($response);
                //recode answercode=answer if codes are non-numeric (cannot be used with value labels)
                if ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['nonnumericanswercodes'] == true 
                    && $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['commentother'] == false)
                {
                    // set $iScaleID to the scale_id of the respective question, if it exists...if not set to '0'
                    $iScaleID = 0;
                    if (isset($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['scale']))
                    {
                        $iScaleID = $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['scale_id'];
                    }
                    $iQID                                       = $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['qid'];
                    if (isset($this->customFieldmap['answers'][$iQID][$iScaleID][$response]['answer']))
                    {
                        $response = trim($this->customFieldmap['answers'][$iQID][$iScaleID][$response]['answer']); // get answertext instead of answercode
                    }
                }
                
                if ($response == '')
                {
                    $aDatatypes[$this->headersSGQA[$iVarid]][$iRespId] = 'emptystr';
                }
                else
                {
                    // recode some values from letters to numeric, so we can attach value labels and have more time doing statistics
                    switch ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'])
                    {
                        case "G": //GENDER drop-down list
                            $response = str_replace(array(
                                'F',
                                'M'
                            ), array(
                                '0',
                                '1'
                            ), $response);
                            break;
                        case "Y": //YES/NO radio-buttons
                        case "C": //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                            $response = str_replace(array(
                                'Y',
                                'N',
                                'U'
                            ), array(
                                '1',
                                '0',
                                '9'
                            ), $response);
                            break;
                        case "E": //ARRAY (Increase/Same/Decrease) radio-buttons
                            $response = str_replace(array(
                                'I',
                                'S',
                                'D'
                            ), array(
                                '1',
                                '0',
                                '-1'
                            ), $response);
                            break;
                        case "D": //replace in customResponsemap: date/time as string with STATA-timestamp
                            $response = strtotime($response . ' GMT') * 1000 + 315619200000; // convert seconds since 1970 (UNIX) to milliseconds since 1960 (STATA)
                            break;
                    }
                    
                    // look at each of the responses and fill $aDatatypes with the respective STATA data type 
                    $numberresponse = trim($response);
                    if ($this->customFieldmap['info']['surveyls_numberformat'] == 1) // if settings: decimal seperator==','
                    {
                        $numberresponse = str_replace(',', '.', $response); // replace comma with dot so STATA can use float variables
                    }
                    
                    if (is_numeric($numberresponse)) // deal with numeric responses/variables
                    {
                        if (ctype_digit($numberresponse)) // if it contains only digits (no dot) --> non-float number
                        {
                            if ($numberresponse >= $this->minByte && $numberresponse <= $this->maxByte)
                            {
                                $aDatatypes[$this->headersSGQA[$iVarid]][$iRespId] = 'byte'; //this response is of STATA type 'byte'
                            }
                            elseif ($numberresponse >= $this->minInt && $numberresponse <= $this->maxInt)
                            {
                                $aDatatypes[$this->headersSGQA[$iVarid]][$iRespId] = 'int'; // and this is is 'int'
                            }
                            else
                            {
                                if ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'] == 'D') // if datefield then a 'double' data type is needed
                                {
                                    $aDatatypes[$this->headersSGQA[$iVarid]][$iRespId] = 'double';
                                }
                                else
                                {
                                    $aDatatypes[$this->headersSGQA[$iVarid]][$iRespId] = 'long';
                                }
                            }
                        }
                        else //non-integer numeric response
                        {
                            $aDatatypes[$this->headersSGQA[$iVarid]][$iRespId] = 'float';
                            $response = $numberresponse;     //replace in customResponsemap: value with '.' as decimal
                        }
                    }
                    else // non-numeric response
                    {
                        $aDatatypes[$this->headersSGQA[$iVarid]][$iRespId]  = 'string';
                        $strlenarray[$this->headersSGQA[$iVarid]][$iRespId] = strlen($response); //for strings we need the length as well for the data type
                    }
                }
                $this->customResponsemap[$iRespId][$iVarid]=$response;  //write the recoded response back to the response array
            }
        }
        // create an array $typelist from $aDatatypes with content: variable=> data type and data format
        foreach ($aDatatypes as $variable => $responses)
        {
            if (in_array('string', $responses, true))
            {
                $max                           = max($strlenarray[$variable]); // get maximum string length per string variable
                $typelist[$variable]['type']   = 'str' . $max = $max > 244 ? 244 : $max; // cap str[length] at str244
                $typelist[$variable]['format'] = '%' . $max = $max > 244 ? 244 : $max . 's';
            }
            elseif (in_array('double', $responses, true)) // only used for dates/times (milliseconds passed since 1960)
            {
                $typelist[$variable]['type']   = 'double';
                $typelist[$variable]['format'] = '%tc';
            }
            elseif (in_array('float', $responses, true))
            {
                $typelist[$variable]['type'] = 'float';
                $typelist[$variable]['format'] = '%10.0g';
            }
            elseif (in_array('long', $responses, true))
            {
                $typelist[$variable]['type'] = 'long';
                $typelist[$variable]['format'] = '%10.0g';
            }
            elseif (in_array('integer', $responses, true))
            {
                $typelist[$variable]['type'] = 'integer';
                $typelist[$variable]['format'] = '%10.0g';
            }
            elseif (in_array('byte', $responses, true))
            {
                $typelist[$variable]['type'] = 'byte';
                $typelist[$variable]['format'] = '%10.0g';
            }
            elseif (in_array('emptystr', $responses, true))
            {
                $typelist[$variable]['type'] = 'str1'; //variables that only contain '' as responses will be a short string...
                $typelist[$variable]['format'] = '%1s';
            }
            $this->customFieldmap['questions'][$variable]['statatype']   = $typelist[$variable]['type'];
            $this->customFieldmap['questions'][$variable]['stataformat'] = $typelist[$variable]['format'];
            
            
        }
    }
    
    /* Utilizes customFieldmap[], customResponsemap[], headers[] and xmlwriter() 
     * to output STATA-xml code in the following order
     * - headers
     * - descriptors: data types, list of variables, sorting variable, variable formatting, list of value labels, variable label
     * - data
     * - value labels
     */
    public function close()
    {
        
        $this->updateCustomresponsemap();
        
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        
        //header
        $xml->startDocument('1.0', 'US-ASCII');
        $xml->startElement('dta');
        $xml->startElement('header');
        $xml->writeElement('ds_format', 113);
        $xml->writeElement('byteorder', 'LOHI');
        $xml->writeElement('filetype', 1);
        $xml->writeElement('nvar', count($this->customFieldmap['questions']));
        $xml->writeElement('nobs', count($this->customResponsemap));
        $xml->writeElement('data_label', $this->customFieldmap['info']['surveyls_title'] . ' (SID: ' . $this->customFieldmap['info']['sid'] . ')');
        $xml->writeElement('time_stamp', date('d M Y H:i'));
        $xml->endElement(); // close header
        
        //open descriptors
        $xml->startElement('descriptors');
        
        
        //typelist
        $xml->startElement('typelist');
        foreach ($this->customFieldmap['questions'] as $question)
        {
            $xml->startElement('type');
            $xml->writeAttribute('varname', $question['varname']);
            $xml->text($question['statatype']);
            $xml->endElement();
        }
        $xml->endElement(); // close typelist
        
        //varlist
        $xml->startElement('varlist');
        foreach ($this->customFieldmap['questions'] as $question)
        {
            $xml->startElement('variable');
            $xml->writeAttribute('varname', $question['varname']);
            $xml->endElement(); // close variable
        }
        $xml->endElement(); // close varlist
        
        //fmtlist
        $xml->startElement('fmtlist');
        foreach ($this->customFieldmap['questions'] as $question)
        {
            $xml->startElement('fmt');
            $xml->writeAttribute('varname', $question['varname']);
            $xml->text($question['stataformat']);
            $xml->endElement(); //close fmt
        }
        $xml->endElement(); // close fmtlist
        
        //lbllist
        $xml->startElement('lbllist');
        foreach ($this->customFieldmap['questions'] as $question)
        {
            $xml->startElement('lblname');
            $xml->writeAttribute('varname', $question['varname']);
            if (!empty($this->customFieldmap['answers'][$question['qid']]) && $question['commentother'] == false && $question['nonnumericanswercodes'] == false)
            {
                $iScaleID = isset($question['scale_id']) ? $question['scale_id'] : 0;
                $xml->text('vall' . $question['qid'] . $iScaleID);
            }
            $xml->endElement(); //close lblname
        }
        $xml->endElement(); // close lbllist
        $xml->endElement(); // close descriptors
        
        //variable labels
        $xml->startElement('variable_labels');
        foreach ($this->customFieldmap['questions'] as $question)
        {
            $xml->startElement('vlabel');
            $xml->writeAttribute('varname', $question['varname']);
            $xml->text($question['varlabel']);
            $xml->endElement(); //close vlabel
        }
        $xml->endElement(); // close variable_labels
        
        // data
        $xml->startElement('data');
        $iObsnumber = 0;
        foreach ($this->customResponsemap as $aResponses)
        {
            $xml->startElement('o');
            $xml->writeAttribute('num', $iObsnumber);
            $iObsnumber++;
            foreach ($aResponses as $iVarid => $response)
            {
                $xml->startElement('v');
                $xml->writeAttribute('varname', $this->headers[$iVarid]);
                $xml->text($response);
                $xml->endElement(); //close v
            }
            $xml->endElement(); // close o (participant's response array)
        }
        $xml->endElement(); // close data
        
        //value labels
        $xml->startElement('value_labels');
        foreach ($this->customFieldmap['answers'] as $iQid => $aScales)
        {
            foreach ($aScales as $iScaleID => $aAnswercodes)
            {
                if (!array_key_exists($iQid, $this->aQIDnonumericalAnswers))        //if QID is not one of those with nonnumeric answers
                {                                                                   // write value label
                    $xml->startElement('vallab');
                    $xml->writeAttribute('name', 'vall' . $iQid . $iScaleID);
                    foreach ($aAnswercodes as $iAnscode => $aAnswer)
                    {
                        $xml->startElement('label');
                        $xml->writeAttribute('value', $iAnscode);
                        $xml->text($aAnswer['answer']);
                        $xml->endElement(); // close label
                    }
                    $xml->endElement(); // close vallab
                }
                
            }
            
        }
        $xml->endElement(); // close value_labels
        
        $xml->endElement(); // close dta
        $xml->endDocument();
        
        $this->out($xml->outputMemory(), 1);
        
        fclose($this->handle);
    }
}