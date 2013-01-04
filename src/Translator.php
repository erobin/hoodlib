<?php

namespace eRobin 
{ 
    class Translator
    {
        public $languages = array();
        public $messages = array();
        public $did = false;

        private $defaultLanguageID = 'en';
        private $defaultCountryID = 'US';

        private $currentLanguageID;
        private $currentCountryID;

        function __construct() 
        {
          $this->loadLanguages(); /// load the list of enabled/disabled languages
          $this->loadMessages(); /// load the list of enabled/disabled languages
        }

        public function translate($message, $languageID = '', $countryID = '', $module = 0) 
        {
            $this->then = microtime(true);

          if (empty($languageID)) {
                $languageID = Session::get('language_id');
                $countryID = Session::get('country_id');
          }

          if ( !isset($this->languages[$languageID."-".$countryID]) ) { /// if the language is not enabled in the system, use default
            $languageID = $this->defaultLanguageID;
            $countryID = $this->defaultCountryID;
          }

          $this->removePrefixAndSufix($message, $prefix, $sufix); /// spaces and punctuation before and after should not be translated

          $ret = $prefix . $this->__translate($message, $languageID, $countryID, $module) . $sufix;

          if (!isset($ret) or Empty($ret)) {
            $ret = $message; /// no empty messages, ever
          }

          return $ret;
        }

        private function __translate($message, $languageID, $countryID, $module) 
        {
            $hash = SHA1($message);

            $messageID = $this->buildMessageID($hash, $this->defaultLanguageID, $this->defaultCountryID, $module);
            $originalMessage = $this->getMessage($messageID);
            if (!isset($originalMessage) or empty($originalMessage)) {
                $this->newMessage($messageID, $this->defaultLanguageID, $this->defaultCountryID, $module, $hash, $message);
            }

            if (!$this->isDefaultLanguage($languageID, $countryID)) {
                $messageID = $this->buildMessageID($hash, $languageID, $countryID, $module);
                $translatedMessage = $this->getMessage($messageID);
                if ( isset($translatedMessage) and !empty($translatedMessage) ) {
                    return $translatedMessage;
                }
                Log::log("not found = $message - $hash - $messageID");
                $this->newMessage($messageID, $languageID, $countryID, $module, $hash, $message);
            }

            return $message;
        }

        private function newMessage($messageID, $languageID, $countryID, $module, $hash, $message) {
            $model = new Message;
            $model->language_id = $languageID;
            $model->country_id = $countryID;
            $model->module_id = $module;
            $model->message_hash = $hash; // this hash is the untranslated form of the message
            $model->message = $message; // this is the message already translated
            $model->save();
            $result = $message;
            $this->setMessage($messageID,$message);
        }
        
        private function isDefaultLanguage($lID, $cCode) {
            return ($lID == $this->defaultLanguageID) and ($cCode = $this->defaultCountryID);
        }

        private function loadLanguages() {
            $query = DB::query("select cl.language_id, cl.country_id , l.name language_name , c.name country_name , concat(l.name,' (', c.name, ')') as regional_name, cl.enabled  from country_languages cl  join languages l on l.id = cl.language_id  join countries c on c.id = cl.country_id;");
                                             
            foreach ($query as $record) {
                $this->languages[$record->language_id."-".$record->country_id] = array(
                                                                'language_id' => $record->language_id
                                                            , 'country_id' => $record->country_id
                                                            , 'language_name' => $record->language_name
                                                            , 'country_name' => $record->country_name
                                                            , 'regional_name' => $record->regional_name
                                                            , 'enabled' => $record->enabled
                                                        );
            }
        }

        private function loadMessages() {
            $this->messages = array();    
            
            $query = DB::table('messages')->where_language_id($this->defaultLanguageID)->where_country_id($this->defaultCountryID)->get();
            foreach ($query as $record) {
              $messageID = $this->buildMessageID($record->message_hash, $record->language_id, $record->country_id, $record->module_id);
              $this->messages[$messageID] = $record->message;
            }

            $query = DB::table('messages')->where_language_id(Session::get('language_id'))->where_country_id(Session::get('country_id'))->get();
            foreach ($query as $record) {
                $messageID = $this->buildMessageID($record->message_hash, $record->language_id, $record->country_id, $record->module_id);
                $this->messages[$messageID] = $record->message;
            }
        }

        private function removePrefixAndSufix(&$message, &$prefix, &$sufix) {
          $prefix = '';
          $sufix = '';
          
          $chars = array( "!"=>1,"\\"=>1,"\""=>1,"#"=>1,"\$"=>1,"%"=>1,"&"=>1,"'"=>1,"("=>1,")"=>1,"*"=>1,"+"=>1,","=>1,"-"=>1,"."=>1,"/"=>1,":"=>1,";"=>1,"<"=>1,"="=>1,">"=>1,"?"=>1,"@"=>1,"["=>1,"]"=>1,"^"=>1,"_"=>1,"`"=>1,"{"=>1,"|"=>1," "=>1,"}"=>1 );

          $i = 0;
          while ($i < strlen($message) and isset($chars[$message[$i]])) {
              $prefix .= $message[$i];
              $i++;
          }
          $i = strlen($message)-1;
          while ($i > -1 and isset($chars[$message[$i]])) {
              $sufix = $message[$i] . $sufix;
              $i--;
          }

          $message = substr($message,strlen($prefix));
          $message = substr($message,0,strlen($message)-strlen($sufix));
        }

        private function buildMessageID($hash, $languageID, $countryID, $module) {
            return SHA1("$hash, $languageID, $countryID, $module");
        }
        
        private function getMessage($messageID) {
            if (!isset($this->messages[$messageID])) {
                Log::log("not found! |$messageID|");
                if (!$this->did) {
                    foreach($this->messages as $key => $data) {
                        Log::log("$key => $data");
                    }
                    $this->did = true;  
                }
                foreach($this->messages as $key => $message) {
                    if ($key == $messageID) {
                        Log::log("FOUND! |$key| == |$messageID|");
                    }
                }
            }

            return isset($this->messages[$messageID]) ? $this->messages[$messageID] : NULL;
        }
        
        private function setMessage($messageID,$message) {
            return $this->messages[$messageID] = $message;
        }
        
    }

    /// This is a helper function for translation

    function t($message, $language = null, $country = null, $module = 0)
    {
        $translator = IoC::resolve('translator');
        $debug = false;
        return ($debug ? '{' : '').$translator->translate($message, $language, $country, $module).($debug ? '}' : '');
    }

}
 