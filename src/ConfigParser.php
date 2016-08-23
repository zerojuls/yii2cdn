<?php

/**
 * @copyright Copyright (c) 2016 Junaid Atari
 * @link http://junaidatari.com Website
 * @see http://www.github.com/blacksmoke26/yii2-cdn
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 */

namespace yii2cdn;

use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;

/**
 * Class ComponentConfigParser
 * Parse the component configuration array into components
 *
 * @package common\yii2cdn
 * @author Junaid Atari <mj.atari@gmail.com>
 *
 * @access public
 * @version 0.1
 */
class ConfigParser {
	/**
	 * List of sections name
	 * @var array
	 */
	protected static $sections = [];

	/**
	 * Section options
	 * @var array
	 */
	protected static $sectionOptions = [];

	/**
	 * Component ID
	 * @var string
	 */
	protected $_id;

	/**
	 * CDN Base URL
	 * @var string
	 */
	protected $baseUrl;

	/**
	 * CDN Base Path
	 * @var string
	 */
	protected $basePath;

	/**
	 * Component Configuration
	 * @var array
	 */
	protected $config = [];

	/**
	 * CDN Custom aliases
	 * @var array
	 */
	protected $aliases = [];

	/**
	 * Component file name id Configuration
	 * @var array
	 */
	protected $fileIds = [];

	/**
	 * Files attributes
	 * @var array [ID=>Mixed-Value]
	 */
	protected $filesAttrs = [];

	/**
	 * Files attributes
	 * @var array [ID=>Mixed-Value]
	 */
	protected $_props = [];

	/**
	 * Predefined file attributes
	 * @var array
	 */
	protected $defFileAttrs = ['id', 'cdn', 'offline', 'options'];

	/**
	 * ComponentConfigParser constructor.
	 * @param $config Component Configuration
	 */
	public function __construct ( array $config ) {
		$this->_id = $config['id'];
		$this->baseUrl = $config['baseUrl'];
		$this->basePath = $config['basePath'];
		$this->config = $config['config'];
		self::$sections = $config['sections'];
		$this->_props['fileClass'] = $config['fileClass'];
		$this->_props['sectionClass'] = $config['sectionClass'];
	}

	/**
	 * Get the components files list<br>
	 * Key=Value pair of [COMPONENT_ID/SECTION_ID/FILE_ID]=>FILE_URL
	 * @param array $components Pre Build Components data
	 * @return array
	 */
	protected static function listFilesByRoot ( $components ) {
		if ( !count ( $components ) ) {
			return $components;
		}

		$filesId = [ ];
		$componentsUrl = [ ];

		foreach ( $components as $componentId => $sections ) {

			$componentsUrl[$componentId] = $sections['baseUrl'];

			foreach ( $sections as $sectionId => $data ) {
				if ( !in_array ( $sectionId, self::$sections, true ) || !count ( $data ) ) {
					continue;
				}

				foreach ( $data as $fileId => $fileName ) {

					if ( false !== strpos( $fileId, '*' ) ) {
						continue;
					}

					// File unique id
					$uid = "{$componentId}/{$sectionId}/" . $fileId;

					$filesId[$uid] = $fileName;
				}
			}
		}

		return [
			'filesId' => $filesId,
			'componentsUrl' => $componentsUrl
		];
	}

	/**
	 * Replaces @component* tags from the components
	 *
	 * @see ComponentConfigParser::replaceComponentTagsFromFileName()
	 * @param array $components Pre Build Components data
	 * @return array Post components object
	 */
	public static function touchComponentTags ( $components ) {
		if ( !count ( $components ) ) {
			return $components;
		}

		$reListed = self::listFilesByRoot ( $components );

		foreach ( $components as $componentId => $sections ) {

			if ( !count($sections) ) {
				continue;
			}

			foreach ( $sections as $sectionId => $data ) {
				if ( !in_array ( $sectionId, self::$sections, true ) || !count ( $data ) ) {
					continue;
				}

				foreach ( $data as $fileId => $props ) {
					$file = $props;

					if ( preg_match ( '/^@component([A-Za-z]+)/i', $file['url'] ) > 0 ) {
						$file = self::replaceComponentTags ( $file, $reListed );
						if ( $file === false ) {
							continue;
						}
					}

					// update file properties
					$components[$componentId][$sectionId][$fileId] = $file;
				}
			}
		}

		return $components;
	}

	/**
	 * Replaces @component* tags (case insensitive) from given filename
	 * Tags (starts with @component)<br>
	 * <code>
	 *    > componentUrl(ID)
	 *    > componentFile(ID/SECTION/FILE_ID)
	 * </code>
	 *
	 * @param string $fileName File name replace from
	 * @param array $indexed Indexed data object
	 * @return array Replaced tags object
	 */
	protected static function replaceComponentTags ( $file, array $indexed ) {
		$fileName = $file['url'];

		// tag: componentFile(ID/SECTION/FILE_ID)
		if ( preg_match('/^@(?i)componentFile(?-i)\(([^\)]+)\)$/', $fileName, $match) ) {
			if ( !array_key_exists ( $match[1], $indexed['filesId'] ) ) {
				throw new InvalidConfigException ( "Unknown CDN component file id '{$match[1]}' given" );
			}

			list($componentId, $sectionId, $fileId) = explode('/', $match[1]);
			return array_merge($indexed['filesId'][$match[1]], [
				'_component' => $componentId,
				'_section' => $sectionId,
			]);
		}

		// tag: componentUrl(ID)
		if ( preg_match('/^@(?i)componentUrl(?-i)\(([^\)]+)\)(.+)$/', $fileName, $match) ) {
			if ( !array_key_exists ( $match[1], $indexed['componentsUrl'] ) ) {
				throw new InvalidConfigException ( "Unknown CDN component id '{$match[1]}' given" );
			}
			return [
				'file' => $match[2],
				'url' => $indexed['componentsUrl'][$match[1]]
					. ( strpos( $match[2], '/' ) !== 0 ? '/' . $match[2] : $match[2] ),
			];
		}
	}

	/**
	 * Get the parsed configuration
	 *
	 * @return array|null Component config | null when skipped
	 */
	public function getParsed () {
		if ( $this->getAttrOffline () === true && Cdn::isOnline () ) {
			return null;
		}

		$config = [
			'id' => $this->_id,
			'baseUrl' => $this->getUrl (),
			'componentAttributes' => $this->getAttrAttributes(),
			'basePath' => $this->basePath . DIRECTORY_SEPARATOR . ($this->getAttrSrc() ? $this->getAttrSrc() : $this->_id),
			'sectionClass' => $this->_props['sectionClass'],
			'fileClass' => $this->_props['fileClass'],
			'sections' => self::$sections,
		];

		// Validate section names if given
		if ( count ( $offlineSections = $this->getAttrOfflineSections () ) ) {
			foreach ( $offlineSections as $sect ) {
				if ( !in_array ( $sect, self::$sections, true ) ) {
					throw new InvalidConfigException ( "Offline Section '{$sect}' name doesn't exist" );
				}
			}
		}

		foreach ( self::$sections as $section ) {

			if ( in_array ( $section, $offlineSections, true ) && Cdn::isOnline () ) {
				continue;
			}

			// Array of section files
			$config[$section] = $this->getFilesBySection ( $section );
		}

		$config['fileAttrs'] = $this->filesAttrs;
		$config['sectionsAttributes'] = self::$sectionOptions;

		return $config;
	}

	/**
	 * Get @src attribute value (empty when not exist/null)
	 * @return string
	 */
	protected function getUrl () {
		$attrBaseUrl = $this->getAttrBaseUrl ();
		$attrSrc = $this->getAttrSrc ();

		$baseUrl = empty( $attrBaseUrl ) ? $this->baseUrl : $attrBaseUrl;
		$baseUrl .= empty( $attrSrc ) ? '/' . $this->_id : '/' . $attrSrc;

		return $baseUrl;
	}

	/**
	 * Get @offline attribute value (empty when not exist/null)
	 * @return string
	 */
	protected function getAttrOffline () {
		return array_key_exists('@offline', $this->config) && !empty($this->config['@offline'])
			? (bool) $this->config['@offline']
			: false;
	}

	/**
	 * Get @baseUrl attribute value (empty when not exist/null)
	 * @return string
	 */
	protected function getAttrBaseUrl () {
		return array_key_exists('@baseUrl', $this->config) && !empty($this->config['@baseUrl'])
			? trim( $this->config['@baseUrl'] )
			: '';
	}

	/**
	 * Get @attributes attribute value (empty when not exist/null)
	 * @return string
	 */
	protected function getAttrAttributes () {
		return array_key_exists('@attributes', $this->config) && is_array($this->config['@attributes']) && !empty($this->config['@attributes'])
			? $this->config['@attributes']
			: [];
	}

	/**
	 * Get @src attribute value (empty when not exist/null)
	 * @return string
	 */
	protected function getAttrSrc () {
		return array_key_exists ( '@src', $this->config ) && !empty( $this->config['@src'] )
			? trim ( $this->config['@src'] )
			: '';
	}

	/**
	 * Get @offlineSections attribute value (empty when not exist/null)
	 * @return array
	 */
	protected function getAttrOfflineSections () {
		if ( !array_key_exists ( '@offlineSections', $this->config ) ) {
			return [ ];
		}

		$lst = $this->config['@offlineSections'];

		if ( !is_array ( $lst ) ) {
			throw new InvalidParamException ( 'Parameter @offlineSections must be an array' );
		}

		return $this->config['@offlineSections'];
	}

	/**
	 * Get the files of section by name
	 * @param $type string Section name to get
	 * @return array
	 */
	protected function getFilesBySection( $type ) {
		if ( !in_array($type, self::$sections, true) || !isset($this->config[$type])
			|| !is_array($this->config[$type]) || empty($this->config[$type]) ) {
			return [];
		}

		$list = [];

		foreach ( $this->config[$type] as $tag => $file ) {
			$op = $this->getFileName($file, $type, $tag);

			if ( $op === null ) {
				continue;
			}

			$_id = key($op);

			$list[$_id] = $op[$_id];
		}

		return $list;
	}

	/**
	 * Get the file id and name
	 * @param string|array $file File name | file object
	 * @param string $type Section name
	 * @param string $tag (optional) Section attribute tag name
	 * @throws \yii\base\InvalidParamException when File first param must not string or empty
	 * @throws \yii\base\InvalidParamException when File attribute param not string or empty
	 * @return array|null Key=>Value pair (ID=>FILENAME) / File skipped
	 */
	protected function getFileName ( $file, $type, $tag = null ) {
		// Check if element contains section attributes
		if ( $tag === '@attributes' ) {

			if ( !is_array($file) ) {
				throw new InvalidParamException ('@attributes tag should be an array.');
			}

			self::$sectionOptions[$type] = (array) $file;

			return null;
		}

		if ( !is_array($file) || is_string($file) ) {

			if ( preg_match ( '/^@component([A-Za-z]+)/i', $file ) > 0 ) {
				return [  uniqid('*', false) => [
					'file' => null,
					'url' => $file
				]];
			}

			return [  uniqid('*', false) => [
				'file' => ltrim(preg_replace('/^@[a-zA-Z]+/', '', $file), '\\/'),
				'url' => $this->replaceFileNameTags($file, $type) ]
			];
		}

		if ( empty($file[0]) || !is_string($file[0]) ) {
			throw new InvalidParamException ('File first param must be string and not empty');
		}

		$params = ['cdn', 'id'];

		foreach ($params as $p ) {
			if ( !empty($file['@'.$p]) && !is_string($file['@'.$p]) ) {
				throw new InvalidParamException ("File @{$p} param must be string and cannot be emptied");
			}
		}

		if ( array_key_exists('@offline', $file) && $file['@offline'] !== false && Cdn::isOnline() ) {
			return null;
		}

		// Check file @cdn exist, use that version,
		$filename = array_key_exists('@cdn', $file) && Cdn::isOnline()
			? $file['@cdn']
			: $this->replaceFileNameTags($file[0], $type); // use offline version

		// Check file ID, if doesn't exist, assign a unique id
		$fileId = array_key_exists('@id', $file)
			? trim($file['@id'])
			: (string) uniqid('*', false);

		if ( array_key_exists('@options', $file) ) {
			if ( !is_array($file['@options']) ){
				throw new InvalidParamException ( "File @options param should be an array" );
			}

			$this->filesAttrs[$type]["@options/$fileId"] = $file['@options'];
		}

		$attributes = preg_grep( '/^[a-zA-Z]+$/i', array_keys($file) );

		if ( count($attributes) ) {
			foreach ( $attributes as $attr => $val ) {
				if ( in_array($attr, $this->defFileAttrs, true)) {
					continue;
				}

				$this->filesAttrs[$type]["$val/$fileId"] = $file[$val];
			}
		}

		return [$fileId => [
			'file' => $file[0],
			'url' => $filename
		]];
	}

	/**
	 * Replaces tags (starts with @) from file name<br><br>
	 * Rules: (no url appends at beginning if)<br>
	 * <code>
	 *    > An actual url
	 *    > starts with //
	 * </code><br>
	 * Supported tags (case insensitive)<br>
	 * <code>
	 *    > appUrl : current application url
	 *    > baseUrl : component base url
	 *    > thisSectionUrl : parent section base url
	 *    > thisSectionPath : parent section base path
	 *    > url (*) : * = any url ends with /
	 *    > alias (*) : * = CDN custom alias name
	 *    > yiiAlias (*) : * = Yii alias name
	 * </code><br>
	 * @param string $fileName Replace tags from filename
	 * @param string $type Section type name
	 * @return string
	 */
	protected function replaceFileNameTags ( $fileName, $type ) {
		if ( \strpos( $fileName, '//' ) === 0
			|| \filter_var($fileName, \FILTER_VALIDATE_URL )) {
			return $fileName;
		}

		$sectionPath = $this->getSectionBasePath('type');

		// Replace tags
		if ( false !== strpos($fileName, '@') ) {
			$patterns = [
				// tag: @thisComponentUrl
				'/^((?i)@thisComponentUrl(?-i))(.+)$/' => function ($match) use ($type) {
					return $this->getUrl() . $match[2];
				},

				// tag: @thisSectionUrl
				'/^((?i)@thisSectionUrl(?-i))(.+)$/' => function ($match) use ($type) {
					return $this->getSectionUrl($type, $match[2]);
				},

				// tag: @alias(*)
				'/^(?i)@alias(?-i)\(([^\)]+)\)(.+)$/' => function ($match) {
					if (!array_key_exists($match[1], $this->aliases) ) {
						throw new InvalidConfigException ("Invalid custom url alias '{$match[1]}' given");
					}

					return \Yii::getAlias($match[1]) . ( 0 !== strpos($match[2], '/') ? '/'.$match[2] : $match[2]);
				},

				// tag: @yiiAlias(*)
				'/^(?i)@yiiAlias(?-i)\(([^\)]+)\)(.+)$/' => function ($match) {
					return \Yii::getAlias($match[1]) . ( 0 !== strpos($match[2], '/') ? '/'.$match[2] : $match[2]);
				},

				// tag: @url(*)
				'/^(?i)@url(?-i)\(([^\)]+)\)(.+)$/' => function ($match) {
					return $match[1] . ( 0 !== strpos($match[2], '/') ? '/'.$match[2] : $match[2]);
				},

				// tag: @appUrl
				'/^((?i)@appUrl(?-i))(.+)$/' => function ($match) {
					return $this->baseUrl . $match[2];
				},

				// tag: @baseUrl
				'/^((?i)@baseUrl(?-i))(.+)$/' => function ($match) {
					return $this->getUrl() . $match[2];
				},
			];

			return preg_replace_callback_array($patterns, $fileName);
		}

		return $this->getSectionUrl($type, $fileName);
	}

	/**
	 * Get the section url
	 * @param string $type The type name
	 * @param string|null $fileName (optional) filename append at ned
	 * @param array $attributes (optional) Sectioon attributes
	 * @return string The final url
	 * @throws \yii\base\InvalidConfigException when all of the attributes aren't valid
	 */
	protected function getSectionUrl ( $type, $fileName = null, array $attributes = [] ) {
		$_attributes = !count($attributes) ? self::$sectionOptions[$type] : $attributes;

		// Base Url
		if ( isset(self::$sectionOptions[$type]['baseUrl']) ) {

			if ( !is_string($_attributes['baseUrl']) || !trim($_attributes['baseUrl']) ) {
				throw new InvalidConfigException("Section `{$type}`'s `baseUrl` attribute is not valid ");
			}

			return rtrim($_attributes['baseUrl'], '/') . ( $fileName ? '/'. ltrim($fileName, '/') : '' );
		}

		// (@src) Source directory url
		if ( isset(self::$sectionOptions[$type]['src']) ) {

			if ( !is_string($_attributes['src']) || !trim($_attributes['src']) ) {
				throw new InvalidConfigException("Section `{$type}`'s `src` attribute is not valid ");
			}

			$baseUrl = rtrim($this->getUrl(), '/') . '/' . ltrim($_attributes['src'], '/');

			return $baseUrl . ( $fileName ? '/'. ltrim($fileName, '/') : '' );
		}

		// Section type name url
		return rtrim($this->getUrl(), '/') . "/{$type}/" . ltrim($fileName, '/');
	}

	/**
	 * Get the section base path
	 * @param string $type The type name
	 * @param string|null $fileName (optional) filename append at ned
	 * @param array $attributes (optional) Sectioon attributes
	 * @return string The final basePath
	 * @throws \yii\base\InvalidConfigException when all of the attributes aren't valid
	 */
	protected function getSectionBasePath ( $type, $fileName = null, array $attributes = [] ) {
		$_attributes = !count($attributes) ? self::$sectionOptions[$type] : $attributes;
		$basePath = $this->basePath . DIRECTORY_SEPARATOR . $this->getAttrSrc();

		// (@src) Source directory url
		if ( isset($_attributes['src']) ) {

			if ( !is_string($_attributes['src']) || !trim($_attributes['src']) ) {
				throw new InvalidConfigException("Section `{$type}`'s `src` attribute is invalid ");
			}

			$basePath .= DIRECTORY_SEPARATOR . ltrim($_attributes['src'], '\\/');

			return $basePath . ( $fileName ? DIRECTORY_SEPARATOR. ltrim($fileName, '/\\') : '' );
		}

		// Section type name url
		return $basePath . DIRECTORY_SEPARATOR .'x/' . ltrim($fileName, '/');
	}
}
