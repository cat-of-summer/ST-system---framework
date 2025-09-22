<?php

namespace BX_facade;

use \Bitrix\Main\Loader;

final class WebForm {

    public $ID = null;
    public $arForm = [];
    public $arQuestions = [];
    public $arAnswers = [];
    public $arDropDown = [];
    public $arMultiSelect = [];

    public $questions = [];

    public $question_map = [];
    public $answer_map = [];

    private $FORM_START;
    private $REQUIRED_INPUTS;
    private $FORM_END;

    private static function set_attributes(string $html, array $attrs): string {
        $len = strlen($html);
        $start = strpos($html, '<');
        if ($start === false) return $html;

        $inQuote = null;
        $end = null;
        for ($i = $start; $i < $len; $i++) {
            $ch = $html[$i];
            if ($ch === '"' || $ch === "'") {
                if ($inQuote === null) $inQuote = $ch;
                elseif ($inQuote === $ch) $inQuote = null;
                continue;
            }
            if ($ch === '>' && $inQuote === null) {
                $end = $i;
                break;
            }
        }
        if ($end === null) return $html;

        $opening = substr($html, $start, $end - $start + 1);

        if (!preg_match('/^<\s*([^\s>\/]+)/', $opening, $m)) return $html;
        $tagName = $m[1];

        $selfClose = preg_match('/\/\s*>$/', $opening) === 1;

        $posAfterName = strpos($opening, $tagName) + strlen($tagName);
        $attrStr = substr($opening, $posAfterName, $selfClose ? -2 : -1);
        $attrStr = trim($attrStr);

        $pattern = '/([^\s=\/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>`]+)))?/s';
        preg_match_all($pattern, $attrStr, $matches, PREG_SET_ORDER);

        $existingOrder = [];
        $attrMap = [];

        foreach ($matches as $mm) {
            $name = $mm[1];
            $value = null;
            if (isset($mm[2]) && $mm[2] !== '') $value = $mm[2];
            elseif (isset($mm[3]) && $mm[3] !== '') $value = $mm[3];
            elseif (isset($mm[4]) && $mm[4] !== '') $value = $mm[4];

            $existingOrder[] = $name;
            $attrMap[$name] = $value;
        }

        foreach ($attrs as $k => $v) {
            if ($v === false) {
                unset($attrMap[$k]);
                $idx = array_search($k, $existingOrder, true);
                if ($idx !== false) array_splice($existingOrder, $idx, 1);
            } else {
                $isNew = !array_key_exists($k, $attrMap);
                $attrMap[$k] = $v;
                if ($isNew) $existingOrder[] = $k;
            }
        }

        $parts = [];
        foreach ($existingOrder as $name) {
            if (!array_key_exists($name, $attrMap)) continue;
            $val = $attrMap[$name];
            if ($val === null) {
                $parts[] = $name;
            } else {
                $parts[] = $name . '="' . htmlspecialchars((string)$val, ENT_QUOTES) . '"';
            }
        }

        $newOpening = '<' . $tagName . (!empty($parts) ? ' ' . implode(' ', $parts) : '') . ($selfClose ? ' />' : '>');

        return substr($html, 0, $start) . $newOpening . substr($html, $end + 1);
    }

    public function __construct($webform, array $PARAMS = []) {
        if (!Loader::IncludeModule("form")) 
            throw new \Exception("Не удалось подключить модуль 'form'");

        if (is_array($webform)) {
            $this->ID = $webform['arForm']['ID'];
            $this->arForm = $webform['arForm'];
            $this->arQuestions = $webform['arQuestions'];
            $this->arAnswers = $webform['arAnswers'];
            $this->arDropDown = $webform['arDropDown'];
            $this->arMultiSelect = $webform['arMultiSelect'];

            $this->questions = $webform['QUESTIONS'];

            foreach ($this->questions as $question_code => &$question) {
                $question = array_merge($question, $this->arQuestions[$question_code]);
                $this->question_map[$question['ID']] = $question_code;

                foreach ($question['STRUCTURE'] as $i => &$answer) {
                    $answer = array_merge($answer, $this->arAnswers[$question_code][$i], [
                        'REQUIRED' => $question['REQUIRED'],
                        'REQUIRED_TEXT' => $question['REQUIRED'] == 'Y' ? ' required ' : '',
                        'TYPE' => $answer['FIELD_TYPE'],
                        'NAME' => $answer['FIELD_TYPE'] == 'checkbox'
                            ? "form_".$answer['FIELD_TYPE']."_".$question['VARNAME'].'[]'
                            : ($answer['FIELD_TYPE'] == 'radio'
                                ? "form_".$answer['FIELD_TYPE']."_".$question['VARNAME']
                                : "form_".$answer['FIELD_TYPE']."_".$answer['ID']
                        ),
                        'VALUE' => $answer['FIELD_TYPE'] == 'checkbox' || $answer['FIELD_TYPE'] == 'radio' 
                            ? $answer['ID']
                            : $answer['VALUE']
                    ]);
                    $this->answer_map[$answer['ID']] = [$question_code, $i];
                }

                if (count($question['STRUCTURE']) == 1)
                    $question['STRUCTURE'] = $question['STRUCTURE'][0];
            }
        } else {
            throw new \Exception("Взятие по id пока не поддерживается");
            
            $this->ID = (int)$webform;
            \CForm::GetDataByID(
                $this->ID,
                $this->arForm,
                $this->arQuestions,
                $this->arAnswers,
                $this->arDropDown,
                $this->arMultiSelect,
            );
        }
        
		if (empty($this->arForm))
            throw new \Exception("Не удалось получить объект веб-формы (ID = {$this->ID})");

        $this->FORM_START = "<form name='{$this->arForm["SID"]}' action='".POST_FORM_ACTION_URI."' method='".($PARAMS['method'] ?? 'POST')."' enctype='".($PARAMS['enctype'] ?? 'application/x-www-form-urlencoded')."'>";

        $this->REQUIRED_INPUTS = 
            bitrix_sessid_post().
            "<input type='hidden' name='WEB_FORM_ID' value='$this->ID'/>".
            "<input type='hidden' name='web_form_apply' value='Y'>";
        
        $this->FORM_END = "</form>";
    }

    public function start(array $ATTRS = []) {
        return self::set_attributes($this->FORM_START, $ATTRS).$this->REQUIRED_INPUTS;
    }

    public function end() {
        return $this->FORM_END;
    }

    public function question(mixed $key) {
        $question = null;
        if (isset($this->questions[$key]))
            $question = $this->questions[$key];
        elseif (isset($this->question_map[$key]))
            $question = $this->questions[$this->question_map[$key]];

        if (!$question)
            throw new \Exception("Question not found");
                
        return $question;
    }


}