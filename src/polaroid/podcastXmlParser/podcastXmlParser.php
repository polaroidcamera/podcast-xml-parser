<?php

namespace podcastXMLParser;

class podcastXMLParser {

	/**
	 *
	 */
	private $xml = null;


	/**
	 *
	 */
	private $newXml = array();


	/**
	 *
	 */
	private $feed = null;

	/**
	 *
	 */
	private $newFeedUrl = null;

	/**
	 *
	 */
	public function setFeed($feed) {

		if(is_null($this->feed)) {
			$this->feed = $feed;
		}

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $feed);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER, false);

		$data = curl_exec($curl);
		$retCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		$xml = false;

		if(200 === $retCode) {
			$xml = simplexml_load_string($data);
		} elseif(301 === $retCode || 302 === $retCode) {
			$this->newFeedUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
			return $this->setFeed($this->newFeedUrl);
		} else {
			$xml = false;
		}

		curl_close($curl);

		if($xml) {
			$this->setXml($xml);
		}

		return $feed;
	}

	/**
	 *
	 */
	private function setXml($xml) {
		$this->xml = $xml;
	}

	/**
	 *
	 */
	private function clean_cdata($str, $default = null) {

		$str = trim(strip_tags(str_replace(array("\r", "\n", '#(^\s*<!\[CDATA\[|\]\]>\s*$)#sim'), '', ((((string)$str))))));

		return (empty($str) ? $default : $str);
	}


	/**
	 *
	 */
	private function read() {

		if(false === $this->xml) {

			$this->newXml = false;
			return  false;
		}

		$explicit = array('no','yes','clean');

		$this->newXml = null;

		$this->newXml['author'] = null;
		$this->newXml['block'] = null;
		$this->newXml['category'] = null;
		$this->newXml['copyright'] = str_replace('©️','', $this->clean_cdata($this->xml->channel->copyright));
		$this->newXml['description'] = $this->clean_cdata($this->xml->channel->description);
		$this->newXml['explicit'] = null;
		$this->newXml['feed_url'] = $this->feed;
		$this->newXml['generator'] = $this->clean_cdata($this->xml->channel->generator);
		$this->newXml['image_href'] = null;
		$this->newXml['image_link'] = null;
		$this->newXml['image_title'] = null;
		$this->newXml['image_url'] = null;
		$this->newXml['item'] = null;
		$this->newXml['keywords'] = null;
		$this->newXml['language'] = strtolower($this->clean_cdata($this->xml->channel->language));
		$this->newXml['link'] = $this->clean_cdata($this->xml->channel->link);
		$this->newXml['managingEditor'] = $this->clean_cdata($this->xml->channel->managingEditor);
		$this->newXml['new-feed-url'] = null;
		$this->newXml['owner_name'] = null;
		$this->newXml['owner_email'] = null;
		$this->newXml['subtitle'] = null;
		$this->newXml['summary'] = null;
		$this->newXml['title'] = $this->clean_cdata($this->xml->channel->title);
		$this->newXml['titleCanonical'] = $this->strtocanonical($this->newXml['title']);
		$this->newXml['type'] = null;

		foreach($this->xml->channel->children('itunes', true) as $key => $child) {

			if('keywords' === $key) {

				if(null === $this->clean_cdata(strtolower($child))) {
					$this->newXml[$key] = null;
				} else {
					$this->newXml[$key] = array_map(array($this, "strtocanonical"), explode(',',$this->clean_cdata(strtolower($child))));
				}

			} elseif('category' === $key) {

				$category = true;

				$expl = explode(',', strtolower($this->clean_cdata($child->attributes()->text)));

				foreach($expl as $categoryName) {

					if($child->children('itunes', true)) {

						$category = array();

						foreach($child->children('itunes', true) as $child2) {
							$category[] =  strtolower($this->clean_cdata($child2->attributes()->text));
						}
					}

					$this->newXml[$key][trim($categoryName)] = $category;
				}

			} elseif('image' === $key) {
				foreach($child->attributes() as $imgAttr => $imgVal) {
					$this->newXml[$key.'_'.$imgAttr] = $this->clean_cdata($imgVal);
				}
			} elseif('owner' === $key) {
				foreach($child->children('itunes',true) as $imgAttr => $imgVal) {
					$this->newXml[$key.'_'.$imgAttr] = $this->clean_cdata($imgVal);
				}
			} elseif('explicit' === $key) {

				$tmpval = strtolower($this->clean_cdata($child));
				$this->newXml[$key] = in_array($tmpval, $explicit) ? $tmpval : 'no';

			} elseif('block' === $key) {

				$tmpval = strtolower($this->clean_cdata($child));
				$this->newXml[$key] = in_array($tmpval, array('yes','no')) ? $tmpval : 'no';

			} else {
				$this->newXml[$key] = $this->clean_cdata($child);
			}

			if(is_array($this->newXml['keywords'])) {
				$this->newXml['keywords'] = array_unique($this->newXml['keywords']);
			}
		}

		if(!$this->newXml['new-feed-url']) {
			$this->newXml['new-feed-url'] = $this->newFeedUrl;
		}

		if($this->xml->channel->item && !empty($this->xml->channel->item)) {

			$this->newXml['item'] = array();
			$uniqeIds = array();

			foreach($this->xml->channel->item as $item) {

				$audioLength = false;
				$audioType = false;
				$audioUrl = false;

				if($item->enclosure && $item->enclosure->attributes()) {

					$audioLength = $item->enclosure->attributes()->length;
					$audioType = $item->enclosure->attributes()->type;
					$audioUrl = $item->enclosure->attributes()->url;
				}

				$arr = array(
					'audio_length' => $this->clean_cdata($audioLength),
			        'audio_type' => $this->clean_cdata($audioType),
			        'audio_url' => $this->clean_cdata($audioUrl),
					'author' => null,
					'description' => $this->clean_cdata($item->description),
					'duration' => null,
					'episode' => null,
			        'episodeType' => null,
			        'explicit' => null,
			        'guid' => $this->clean_cdata($item->guid),
			        'image_href' => null,
			        'image_link' => null,
			        'image_title' => null,
			        'image_url' => null,
			        'keywords' => null,
			        'pubDate' => $this->clean_cdata($item->pubDate, 'Fri, 01 Apr 1988 06:37 GMT'),
			        'season' => null,
			        'subtitle' => null,
			        'summary' => null,
			        'title' => $this->clean_cdata($item->title),
					'titleCanonical' => null,
				);

				if($item->children('itunes', true)) {

					foreach($item->children('itunes', true) as $key => $child) {

						if('keywords' === $key) {

							if(null === $this->clean_cdata(strtolower($child))) {
								$arr[$key] = null;
							} else {
								$arr[$key] = array_map(array($this, "strtocanonical"), explode(',', $this->clean_cdata(strtolower($child))));
							}

						} elseif('image' === $key) {
							foreach($child->attributes() as $imgAttr => $imgVal) {
								$arr[$key.'_'.$imgAttr] = $this->clean_cdata($imgVal);
							}

						} elseif('explicit' === $key) {

							$tmpval = strtolower($this->clean_cdata($child));
							$arr[$key] = in_array($tmpval, $explicit) ? $tmpval : 'no';

						} else {
							$arr[$key] = $this->clean_cdata($child);
						}
					}
				}

				if(is_array($arr['keywords'])) {
					$arr['keywords'] = array_unique($arr['keywords']);
				}

				$arr['titleCanonical'] = $this->strtocanonical($arr['title']);

				if(is_array($this->newXml['item']) && !empty($this->newXml['item']))
				{
					$i = 1;
					$newTitle = $arr['titleCanonical'];

					while(in_array($arr['titleCanonical'], $uniqeIds))
					{
						$i++;
						$arr['titleCanonical'] = $newTitle . '-' . $i;
					}

					$uniqeIds[] = $arr['titleCanonical'];
				}

				array_push($this->newXml['item'], $arr);
			}
		}

		return true;
	}

	/**
	 *
	 */
	private function arrayToObject(array $arr) {

		$obj = (object) array();

		if(is_array($arr)) {

			foreach($arr as $key => $value) {

				if(is_array($value)) {
					$obj->{$key} = $this->arrayToObject($value);
				} else {
					$obj->{$key} = $value;
				}
			}
		}

		return $obj;
	}


	/**
	 *
	 */
	public function getObject() {

		$this->read();

		$this->newXml = $this->arrayToObject($this->newXml);

		return $this->newXml;
	}


	/**
	 *
	 */
	public function getArray() {

		$this->read();

		return $this->newXml;
	}

	/**
	 *
	 */
	public function strtocanonical($name) {

		if (strpos($name = htmlentities($name, ENT_QUOTES, 'UTF-8'), '&') !== false) {
	        $name = html_entity_decode(preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|tilde|uml);~i', '$1', $name), ENT_QUOTES, 'UTF-8');
	    }

		$namecanonical = strtolower(iconv("UTF-8", "ASCII//IGNORE", trim($name)));
		$namecanonical = preg_replace('/[^a-z0-9- ]/i','', $namecanonical);
		$namecanonical = preg_replace('/\s+/','-', $namecanonical);
		$namecanonical = preg_replace('/-+/', '-', $namecanonical);

		return $namecanonical;
	}
}