<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Storage\Mimes\Traits\Minifiable;

class JavaScriptMime extends Mime {
    use Minifiable;
    
    public function toHTML(array $config = []): string {
        $type = $config['type'] ?? 'text/javascript';
        $async = $config['async'] ? 'async' : '';
        $defer = $config['defer'] ? 'defer' : '';
        
        return "<script src='{$this->file->getRelativePath()}' type='{$type}' $async $defer></script>";
    }
    
    protected $input;
    
    protected $len = 0;
    
    protected $index = 0;
    
    protected $a = '';
    
    protected $b = '';
    
    protected $c;
    
    protected $last_char;
    
    protected $output;
    
    protected $options;
    
    protected $stringDelimiters = ['\'' => true, '"' => true, '`' => true];
    
    protected static $defaultOptions = ['flaggedComments' => true];
    protected static $keywords = ["delete", "do", "for", "in", "instanceof", "return", "typeof", "yield"];
    protected $max_keyword_len;
    
    protected $locks = [];
    
    public function __minify(string $content, array $config): string {
        try {
            $instance = new static($this->file);
            $content = $instance->lock($content);
            $content = ltrim($instance->minifyToString($content, $config));
            $content = $instance->unlock($content);
            unset($instance);
            
            return $content;
        } catch (\Exception $e) {
            if (isset($instance)) {
                
                
                $instance->clean();
                unset($instance);
            }
            throw $e;
        }
    }
    
    protected function minifyToString($js, $options)
    {
        $this->initialize($js, $options);
        $this->loop();
        $this->clean();
        return $this->output;
    }
    
    protected function initialize($js, $options)
    {
        $this->options = array_merge(static::$defaultOptions, $options);
        $this->input = $js;
        
        $this->input .= PHP_EOL;
        
        $this->len = strlen($this->input);
        
        $this->a = "\n";
        $this->b = "\n";
        $this->last_char = "\n";
        $this->output = "";
        $this->max_keyword_len = max(array_map('strlen', static::$keywords));
    }
    
    protected $noNewLineCharacters = [
        '(' => true,
        '-' => true,
        '+' => true,
        '[' => true,
        '#' => true,
        '@' => true];
    protected function echo($char) {
        $this->output .= $char;
        $this->last_char = $char[-1];
    }
    protected function loop()
    {
        while ($this->a !== false && !is_null($this->a) && $this->a !== '') {
            switch ($this->a) {
                
                case "\r":
                case "\n":
                    
                    if ($this->b !== false && isset($this->noNewLineCharacters[$this->b])) {
                        $this->echo($this->a);
                        $this->saveString();
                        break;
                    }
                    
                    if ($this->b === ' ') {
                        break;
                    }
                
                case ' ':
                    if (static::isAlphaNumeric($this->b)) {
                        $this->echo($this->a);
                    }
                    $this->saveString();
                    break;
                default:
                    switch ($this->b) {
                        case "\r":
                        case "\n":
                            if (strpos('}])+-"\'', $this->a) !== false) {
                                $this->echo($this->a);
                                $this->saveString();
                                break;
                            } else {
                                if (static::isAlphaNumeric($this->a)) {
                                    $this->echo($this->a);
                                    $this->saveString();
                                }
                            }
                            break;
                        case ' ':
                            if (!static::isAlphaNumeric($this->a)) {
                                break;
                            }
                        
                        default:
                            
                            if ($this->a === '/' && ($this->b === '\'' || $this->b === '"')) {
                                $this->saveRegex();
                                continue 3;
                            }
                            $this->echo($this->a);
                            $this->saveString();
                            break;
                    }
            }
            
            $this->b = $this->getReal();
            if ($this->b == '/') {
                $valid_tokens = "(,=:[!&|?\n";
                $last_token = $this->a;
                if ($last_token == " ") {
                    $last_token = $this->last_char;
                }
                if (strpos($valid_tokens, $last_token) !== false) {
                    
                    $this->saveRegex();
                } else if ($this->endsInKeyword()) {
                    
                    $this->saveRegex();
                }
            }
            
        }
    }
    
    protected function clean()
    {
        unset($this->input);
        $this->len = 0;
        $this->index = 0;
        $this->a = $this->b = '';
        unset($this->c);
        unset($this->options);
    }
    
    protected function getChar()
    {
        
        if (isset($this->c)) {
            $char = $this->c;
            unset($this->c);
        } else {
            
            $char = $this->index < $this->len ? $this->input[$this->index] : false;
            
            if (isset($char) && $char === false) {
                return false;
            }
            
            $this->index++;
        }
        if ($char == "\r") {
            $char = "\n";
        }
        
        if ($char !== "\n" && $char < "\x20") {
            return ' ';
        }
        return $char;
    }
    
    protected function peek()
    {
        if ($this->index >= $this->len) {
            return false;
        }
        $char = $this->input[$this->index];
        if ($char == "\r") {
            $char = "\n";
        }
        
        if ($char !== "\n" && $char < "\x20") {
            return ' ';
        }
        return $char;
    }
    protected function getReal()
    {
        $startIndex = $this->index;
        $char = $this->getChar();
        if ($char !== '/') {
            return $char;
        }
        $this->c = $this->getChar();
        if ($this->c === '/') {
            $this->processOneLineComments($startIndex);
            return $this->getReal();
        } elseif ($this->c === '*') {
            $this->processMultiLineComments($startIndex);
            return $this->getReal();
        }
        return $char;
    }
    
    protected function processOneLineComments($startIndex)
    {
        $thirdCommentString = $this->index < $this->len ? $this->input[$this->index] : false;
        
        $this->getNext("\n");
        unset($this->c);
        if ($thirdCommentString == '@') {
            $endPoint = $this->index - $startIndex;
            $this->c = "\n" . substr($this->input, $startIndex, $endPoint);
        }
    }
    
    protected function processMultiLineComments($startIndex)
    {
        $this->getChar(); 
        $thirdCommentString = $this->getChar();
        
        if ($thirdCommentString == "*") {
            $peekChar = $this->peek();
            if ($peekChar == "/") {
                $this->index++;
                return;
            }
        }
        
        if ($this->getNext('*/')) {
            $this->getChar(); 
            $this->getChar(); 
            $char = $this->getChar(); 
            
            if (($this->options['flaggedComments'] && $thirdCommentString === '!')
                || ($thirdCommentString === '@')) {
                
                if ($startIndex > 0) {
                    $this->echo($this->a);
                    $this->a = " ";
                    
                    if ($this->input[($startIndex - 1)] === "\n") {
                        $this->echo("\n");
                    }
                }
                $endPoint = ($this->index - 1) - $startIndex;
                $this->echo(substr($this->input, $startIndex, $endPoint));
                $this->c = $char;
                return;
            }
        } else {
            $char = false;
        }
        if ($char === false) {
            throw new \RuntimeException('Unclosed multiline comment at position: ' . ($this->index - 2));
        }
        
        $this->c = $char;
    }
    
    protected function getNext($string)
    {
        
        $pos = strpos($this->input, $string, $this->index);
        
        if ($pos === false) {
            return false;
        }
        
        $this->index = $pos;
        
        return $this->index < $this->len ? $this->input[$this->index] : false;
    }
    
    protected function saveString()
    {
        $startpos = $this->index;
        
        $this->a = $this->b;
        
        if (!isset($this->stringDelimiters[$this->a])) {
            return;
        }
        
        $stringType = $this->a;
        
        $this->echo($this->a);
        
        while (($this->a = $this->getChar()) !== false) {
            switch ($this->a) {
                
                case $stringType:
                    break 2;
                
                case "\n":
                    if ($stringType === '`') {
                        $this->echo($this->a);
                    } else {
                        throw new \RuntimeException('Unclosed string at position: ' . $startpos);
                    }
                    break;
                
                case '\\':
                    
                    $this->b = $this->getChar();
                    
                    if ($this->b === "\n") {
                        break;
                    }
                    
                    $this->echo($this->a . $this->b);
                    break;
                default:
                $this->echo($this->a);
            }
        }
    }
    
    protected function saveRegex()
    {
        if ($this->a != " ") {
            $this->echo($this->a);
        }
        $this->echo($this->b);
        
        
        $character_class = false;
        $character_class_index = null;
        while (($this->a = $this->getChar()) !== false) {
            if ($this->a === '/' && !$character_class) {
                break;
            }
            
            if ($this->a === '[') {
                $character_class = true;
                $character_class_index = $this->index;
            } elseif ($this->a === ']') {
                $character_class = false;
            }
            if ($this->a === '\\') {
                $this->echo($this->a);
                $this->a = $this->getChar();
            }
            if ($this->a === "\n") {
                if ($character_class) {
                    throw new \RuntimeException('Unclosed character class at position: ' . $character_class_index);
                }
                throw new \RuntimeException('Unclosed regex pattern at position: ' . $this->index);
            }
            $this->echo($this->a);
        }
        $this->b = $this->getReal();
    }
    
    protected static function isAlphaNumeric($char)
    {
        return preg_match('/^[\w\$\pL]$/', $char) === 1 || $char == '/';
    }
    protected function endsInKeyword() {
        $testOutput = substr($this->output . $this->a, -1 * ($this->max_keyword_len + 10));
        foreach(static::$keywords as $keyword) {
            if (preg_match('/[^\w]'.$keyword.'[ ]?$/i', $testOutput) === 1) {
                return true;
            }
        }
        return false;
    }
    protected function lock($js)
    {
        
        $lock = '"LOCK---' . crc32(time()) . '"';
        $matches = [];
        preg_match('/([+-])(\s+)([+-])/S', $js, $matches);
        if (empty($matches)) {
            return $js;
        }
        $this->locks[$lock] = $matches[2];
        $js = preg_replace('/([+-])\s+([+-])/S', "$1{$lock}$2", $js);
        
        return $js;
    }
    
    protected function unlock($js)
    {
        if (empty($this->locks)) {
            return $js;
        }
        foreach ($this->locks as $lock => $replacement) {
            $js = str_replace($lock, $replacement, $js);
        }
        return $js;
    }
}
