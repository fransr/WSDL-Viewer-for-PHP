<?
/**
 * WSDL Viewer for PHP v1.0
 * 
 * Used to get a visible overview of a WSDL service. The class will also send test requests built up according to the spec.
 * To visualize it, you can use a template with three tags to specify what should be the output:
 * <!-- definitions --> HTML here! <!-- /definitions --> 		To specify the definition list.
 * <!-- definition --> HTML here! <!-- /definition -->			To specify the single definition of an operation inside the definition list.
 * <!-- operation --> HTML here! <!-- /operation -->			To specify how the page of a single operation looks like.
 * 
 * You also have a few tags inside these pages:
 * 
 * On all pages:
 * 		#wsdl#				Link to the WSDL
 * 		#service#			PHP_SELF
 * 
 * Inside a definition:
 * 		#operation#			The operation name
 * 		#documentation#		The information from the documentation tag
 *	
 * Inside an operation:
 * 		#operation#			Same as per above.
 * 		#documentation#		Same as per above.
 * 		#request#			A test request built up from the WSDL.
 * 		#response#			A response from the service.
 * 
 * 
 * If you are using you own Web Service there are a few calls you could make before the preview of the request/response.
 * Auth
 * 		If you need to authorize with a command before you try it, you could specify this like this:
 * 		$wsdlviewer->auth(true, 'connect', array('username' => 'username', 'password' => 'password'));
 * 
 * Demo Mode
 * 		If your server supports putting all requests to demo mode (just making a response back), you could specify this aswell:
 * 		$wsdlviewer->demo(true, 'demo');
 * 
 * Example:
 * 1. Specify file, template and namespace (the targetNamespace, used for s:string / s:int etc) in the construct by WSDLViewer(array('settings' => array('file' => ..)).
 * 2. Specify functions to initiate demo/auth modes.
 * 3. Execute with ->output()
 * 
 * Missing features: 
 * Doesn't handle enum-restrictions.
 * Doesn't handle inline authorization nor SOAP-Header auth.
 * 
 * Disclaimer: The idea of this came up while integrating to E-Conomic (e-conomic.com) since they have a pretty neat documentation of their service,
 * probably using some .NET module for generating it:
 * https://www.e-conomic.com/secure/api1/EconomicWebservice.asmx
 * For the test template the look is pretty much like theirs.
 * 
 * LICENSE: License is granted to use or modify this software
 * ("WSDL Viewer for PHP") for commercial or non-commercial use provided the
 * copyright of the author is preserved in any distributed or derivative work.
 * 
 * @category   Web Services
 * @author     Frans Rosén <frans@youngskilled.com>
 */
class WSDLViewer {
	private $settings = array(), $wsdl = false, $needs_auth = false, $auth_arguments = null, $auth_operation = null;
	private $demo = false, $demo_operation = null, $demo_arguments = null;
	public function __construct($settings) {
		if(!isset($settings['wsdl'])) die('WSDL needed.');
		if(!isset($settings['template'])) die('Template needed.');
		$this->settings = $settings;
		if(!isset($settings['namespace'])) $this->settings['namespace'] = 's'; //default namespace
	}
	private function get_wsdl($ns = 'http://schemas.xmlsoap.org/wsdl/') {
		if(!$this->wsdl) {
			$this->wsdl = simplexml_load_file($this->settings['wsdl']);
		}
		return $this->wsdl->children($ns);
	}
	private function get_tpl() {
		$data = file_get_contents($this->settings['template']);
		//get both definition and operation
		preg_match('/<!-- definition -->(.*?)<!-- \/definition -->/si', $data, $definition);
		preg_match('/<!-- operation -->(.*?)<!-- \/operation -->/si', $data, $operation);
		$definition = $definition[1];
		$operation = $operation[1];
		
		//start replacing it all.
		$data = preg_replace('/<!-- definition -->(.*?)<!-- \/definition -->/si', '#definition#', $data);
		
		//the definitions-page should now be matched, since the params are replaced.
		preg_match('/<!-- definitions -->(.*?)<!-- \/definitions -->/si', $data, $definitions);
		$definitions = $definitions[1];
		
		//we're now ready to empty them aswell.
		$data = preg_replace('/<!-- definitions -->(.*?)<!-- \/definitions -->/si', '#definitions#', $data);
		$data = preg_replace('/<!-- operation -->(.*?)<!-- \/operation -->/si', '#operation#', $data);
		return array('operation' => $operation, 'definitions' => $definitions, 'definition' => $definition, 'container' => $data);
	}
	private function get_operation() {
		return @$_GET['op'];
	}
	static function operation_sort($a, $b) {
		$a_att = $a->attributes();
		$b_att = $b->attributes();
		return strcmp($a_att['name'], $b_att['name']);
	}
	/**
	 * Finalization function, will build all arguments and post all calls to the SOAP server.
	 */
	public function output() {
		//get xml
		$xml = $this->get_wsdl();
		$tpl = $this->get_tpl();
		$current_operation = $this->get_operation();
		$operation_buffer = '';
		$definition_buffer = '';
		$operation_nodes = $xml->portType;
		// we need to get it to an array so we could use usort to sort it.
		$tmp = array();
		foreach($operation_nodes->operation as $operation) {
			$tmp[] = $operation;	
		}
		unset($operation_nodes);
		$operation_list = $tmp;
		usort($operation_list, array($this, 'operation_sort'));
		foreach($operation_list as $operation) {
			$attribs = $operation->attributes();
			if($current_operation) {
				//we have an active operation
				if($attribs['name'] == $current_operation) {
					$reqres = $this->get_requestresponse($current_operation);
					$operation_buffer .= str_replace(array('#operation#', '#documentation#', '#request#', '#response#'), array($attribs['name'], $operation->documentation, $reqres['request'], $reqres['response']), $tpl['operation']);	
					$definition_buffer = '';
					$tpl['definitions'] = '';
					break;
				}
			} else {
				//list all operations in a definition list.
				$definition_buffer .= str_replace(array('#operation#', '#documentation#'), array($attribs['name'], $operation->documentation), $tpl['definition']);	
			}
		}
		echo str_replace(array('#definitions#', '#definition#', '#operation#', '#service#', '#wsdl#'), array($tpl['definitions'], $definition_buffer, $operation_buffer, $_SERVER['PHP_SELF'], $this->settings['wsdl']), $tpl['container']);
	}
	private function xml_indent($xml) {
		$simple = simplexml_load_string($xml);
		$doc = new DOMDocument('1.0');
		$doc->formatOutput = true;
		$domnode = dom_import_simplexml($simple);
		$domnode = $doc->importNode($domnode, true);
		$domnode = $doc->appendChild($domnode);
		$data = htmlspecialchars($doc->saveXML());
		$data = str_replace(array('&gt;string&lt;', '&gt;0&lt;'), array('&gt;<span class="value">string</span>&lt;', '&gt;<span class="value">int</span>&lt;'), $data);
		$data = preg_replace(array('/\*(.+?)\*/'), array('<span class="value">$1</span>'), $data);
		return $data;
	}
	/**
	 * Call this function to initiate an auth call before test request.
	 * @param bool $needs_auth
	 * @param string $operation
	 * @param array $arguments array of all arguments to the auth call.
	 */
	public function auth($needs_auth = false, $operation = '', $arguments = array()) {
		$this->needs_auth = $needs_auth;
		$this->auth_operation = $operation;
		$this->auth_arguments = $arguments;
	}
	/**
	 * Call this function to initiate a demo call before test request
	 * @param bool $demo
	 * @param string $operation
	 * @param array $arguments array of all arguments to the demo call.
	 */
	public function demo($demo = false, $operation = '', $arguments = array()) {
		$this->demo = $demo;
		$this->demo_operation = $operation;
		$this->demo_arguments = $arguments;
	}
	private function get_requestresponse($operation) {
		$client = new SoapClient($this->settings['wsdl'], array("trace" => 1, 'features' => SOAP_SINGLE_ELEMENT_ARRAYS));
		if($this->needs_auth) {
			$client->__soapCall($this->auth_operation, array($this->auth_arguments));
		}
		if($this->auth_operation == $operation) {
			//we need to use the first response, since else it'll show bad credentials.
			$response = $this->clean_headers($client->__getLastResponseHeaders())."\n".$this->xml_indent($client->__getLastResponse());
		}
		if($this->demo) {
			$client->__soapCall($this->demo_operation, array($this->demo_arguments));
		}
		try {
			$arguments = $this->get_arguments_by_operation($operation);
			$client->__soapCall($operation, array($arguments));
		} catch(Exception $e) {
			
		}
		
		$request = $this->clean_headers($client->__getLastRequestHeaders()).$this->xml_indent($client->__getLastRequest());
		if(!isset($response)) $response = $this->clean_headers($client->__getLastResponseHeaders())."\n".$this->xml_indent($client->__getLastResponse());
		return array('request' => $request, 'response' => $response);
	}
	private function clean_headers($data) {
		$data = explode("\n", $data);
		$headers = array();
		foreach($data as $line) {
			$line = explode(': ', $line);
			$key = $line[0];
			$headers[$key] = implode(': ', $line);
		}
		unset($headers['Content-Length']);
		unset($headers['User-Agent']);
		unset($headers['Cookie']);
		return implode("\n", $headers);
	}
	private function get_arguments_by_operation($operation) {
		$args = array();
		$xml = $this->get_wsdl();
		//To skip namespaces we get all, and take the parent so we know it's portType.
		$parent_nodes = $xml->xpath("//*[@name='".$operation."']/..");
		foreach($parent_nodes as $op) {
			if($this->remove_ns($op->getName()) == 'portType') {
				//bingo!
				break;
			}
		}
		$operation = $op->xpath("*[@name='".$operation."']");
		$inputs = $operation[0]->xpath("*");
		foreach($inputs as $input) {
			if($this->remove_ns($input->getName()) == 'input') {
				//bingo!
				break;
			}
		}
		$parts = $xml->xpath("//*[@name='".$this->remove_ns($input[0]['message'])."']/*");
		foreach($parts as $part) {
			if($this->remove_ns($part) == 'part') {
				//we got it!
				break;
			}
		}
		$element = $this->remove_ns($part[0]['element']);
		$args = $this->build_element($xml, $element, $args);
		return $args;
	}
	private function build_element($xml, $element, &$args) {
		$xml = str_replace(array('<'.$this->settings['namespace'].':', '</'.$this->settings['namespace'].':'), array('<', '</'), file_get_contents($this->settings['wsdl']));
		$xml = simplexml_load_string($xml);
		switch($element) {
			case 'int': case 'string':
				$ret_elem = $element;
			break;
			default: 
				$req_parents = $xml->xpath("//*[@name='".$element."']/..");
				foreach($req_parents as $req_parent) {
					if($this->remove_ns($req_parent->getName()) == 'schema') {
						$req_elements = $req_parent->xpath("*[@name='".$element."']");
						//could be multiple, but refering to one particular. check type and see if the type is the same as the name, then skip.
						foreach($req_elements as $req_element) {
							if(@$this->remove_ns($req_element['type']) != $element) {
								//we found the correct one.
								break;
							} else {
								
							}
						}
					}
				}
				$ret_elem = $this->parse_elements($xml, $req_element);
			break;
		}
		
		return $ret_elem;
	}
	private function parse_elements($xml, $elem) {
		if(!$elem) return;
		$elem_type = (string)$elem->getName();
		switch($elem_type) {
			case 'element':
				$type = @(string)$elem['type'];
				if(!$type) {
					//this is a parent node! we need to go down...
					$ret = array();
					$len = count($elem->children());
					foreach($elem->children() as $c_elem) {
						$return = $this->parse_elements($xml, $c_elem);
						if($len > 1) $ret[] = $return; else $ret = $return;
					}
					return $ret;
				} else {
					$type = $this->remove_ns($type);
					$name = @(string)$elem['name'];
					$fixed = @(string)$elem['fixed'];
					if($fixed) {
						return array($name => $fixed);
					}
					switch($type) {
						case 'dateTime':
							return array($name => date("c"));
						break;
						case 'time':
							return array($name => date("H:i:s"));
						break;
						case 'date':
							return array($name => date("Y-m-d"));
						break;
						case 'decimal':
							return array($name => '0.0');
						break;
						case 'string': 
						case 'int':
							return array($name => $type);
						break;
						case 'boolean':
							return array($name => true);
						break;
						case 'nonNegativeInteger': case 'positiveInteger':
							return array($name => 0);
						break;
						default: 
							$parent_elems = $xml->xpath("//*[@name='".$type."']/..");
							foreach($parent_elems as $par_elem) {
								if($this->remove_ns($par_elem->getName()) == 'schema') {
									$elems = $par_elem->xpath("*[@name='".$type."']");
									foreach($elems as $elem) {
										if(@$this->remove_ns($elem['type']) != $type) {
											//we found the correct one.
											break;
										} else {
											
										}
									}
								} 
							}
							return array($name => $this->parse_elements($xml, $elem));
						break;
					}
				}
			break;
			case 'sequence': case 'complexType':
				$len = count($elem->children());
				$ret = array();
				foreach($elem->children() as $c_elem) {
					$return = $this->parse_elements($xml, $c_elem);
					if(is_array($return)) $ret = array_merge($ret, $return);
				}
				return $ret;
			break;
			case 'annotation':
				return false;
			break;
		}
	}
	private function remove_ns($str) {
		$str = explode(':', $str);
		if(count($str) > 1) array_shift($str);
		return implode(':', $str);
	}
}
/*
 * EXAMPLE USAGE
 */
	$file = 'wsdl.wsdl';
	$wsdlviewer = new WSDLViewer(array('wsdl' => $file, 'template' => 'template.html'));
	//settings.
	$wsdlviewer->auth(true, 'connect', array('username' => 'user', 'password' => 'pass'));
	$wsdlviewer->demo(true, 'demo');
	$wsdlviewer->output();