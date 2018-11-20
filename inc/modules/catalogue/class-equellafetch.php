<?php

/**
 * The class makes various queries to the Equella's REST API's.
 * Equella comes with an overview of the REST API http://solr.bccampus.ca:8001/bcc/apidocs.do
 * October 2012
 *
 * @package   Pressbooks_Textbook
 * @author    Brad Payne
 * @license   GPL-2.0+
 */

namespace PBT\Modules\Catalogue;

class EquellaFetch {

	private $api_base_url          = 'http://solr.bccampus.ca:8001/bcc/api/';
	private $subject_path_1        = '/xml/item/subject_class_level1';
	private $subject_path_2        = '/xml/item/subject_class_level2';
	private $contributor_path      = '/xml/contributordetails/institution';
	private $keyword_path          = '/xml/item/keywords';
	private $where_clause          = '';
	private $url                   = '';
	private $just_the_results_maam = [];
	private $available_results     = 0;
	private $search_term           = '';
	private $keyword_flag          = false;
	private $by_contributor_flag   = false;
	private $uuid                  = '';
	private $collection_uuid       = '7567d816-90cc-4547-af7a-3dbd43277639';

	const OPR_IS      = ' is ';
	const OPR_OR      = ' OR ';
	const ALL_RECORDS = '_ALL';

	/**
	 *
	 */
	public function __construct() {
		$this->searchBySubject( $this->search_term );
	}

	public function getUuid() {
		return $this->uuid;
	}

	public function getKeywordFlag() {
		return $this->keyword_flag;
	}

	public function getContributorFlag() {
		return $this->by_contributor_flag;
	}

	public function getResults() {
		return $this->just_the_results_maam;
	}

	public function getWhereClause() {
		return $this->where_clause;
	}

	/**
	 * Private helper function that url encodes input (with + signs as spaces)
	 * @param item that needs encoding
	 *
	 * @return the encoded item
	 */
	private function urlEncode( $any_string ) {
		$result = '';
		if ( ! empty( $any_string ) ) {
			$result = urlencode( $any_string );
			return $result;
		} else {
			return false;
		}
	}

	/**
	 * Private helper function that rawURL encodes (with %20 as spaces)
	 *
	 * @param string $any_string
	 *
	 * @return string - the encoded item, or false if it's empty
	 */
	private function rawUrlEncode( $any_string ) {
		if ( ! empty( $any_string ) ) {
			return rawurlencode( $any_string );
		} else {
			return false;
		}
	}

	/**
	 * Makes a request to the API for resources by subject/or keyword. This method builds the
	 * REST url and sets the response (json to an associative array) and size in instance variables
	 *
	 * @param string $any_query
	 * @param string $order
	 * @param int $start
	 * @param array $info
	 * @param int $limit
	 *
	 * @throws \Exception
	 */
	private function searchBySubject( $any_query = '', $order = 'modified', $start = 0, $info = [ 'basic', 'metadata', 'detail', 'attachment', 'drm' ], $limit = 0 ) {

		//the limit for the API is 50 items, so we need 50 or less. 0 is 'limitless' so we need to set
		//it to the max and loop until we reach all available results, 50 at a time.
		$limit = ( $limit == 0 || $limit > 50 ? $limit = 50 : $limit = $limit );

		$first_subject_path  = '';
		$second_subject_path = '';
		$is                  = $this->rawUrlEncode( self::OPR_IS );
		$or                  = $this->rawUrlEncode( self::OPR_OR );
		$optional_param      = '&info=' . $this->arrayToCSV( $info ) . '';

		// if there's a specified user query, deal with it, change the order
		// to relevance as opposed to 'modified' (default)
		if ( $any_query != '' ) {
			$order     = 'relevance';
			$any_query = $this->rawUrlEncode( $any_query );
			$any_query = 'q=' . $any_query . '&';
		}

		// start building the URL
		$search_where = 'search?' . $any_query . '&collections=' . $this->collection_uuid . '&start=' . $start . '&length=' . $limit . '&order=' . $order . '&where=';   //limit 50 is the max results allowed by the API
		//switch the API url, depending on whether you are searching for a keyword or a subject.
		if ( empty( $this->where_clause ) ) {
			$this->url = $this->api_base_url . $search_where . $optional_param;
		} // SCENARIOS, require three distinct request urls depending...
		// 1
		elseif ( $this->keyword_flag == true ) {
			$first_subject_path = $this->urlEncode( $this->keyword_path );
			//oh, the API is case sensitive so this broadens our results, which we want
			$second_where = strtolower( $this->where_clause );
			$first_where  = ucwords( $this->where_clause );
			$this->url    = $this->api_base_url . $search_where . $first_subject_path . $is . "'" . $first_where . "'" . $or . $first_subject_path . $is . "'" . $second_where . "'" . $optional_param;  //add the base url, put it all together
		} // 2
		elseif ( $this->by_contributor_flag == true ) {
			$first_subject_path = $this->urlEncode( $this->contributor_path );
			$this->url          = $this->api_base_url . $search_where . $first_subject_path . $is . "'" . $this->where_clause . "'" . $optional_param;
		} // 3
		else {
			$first_subject_path  = $this->urlEncode( $this->subject_path_1 );
			$second_subject_path = $this->urlEncode( $this->subject_path_2 );
			$this->url           = $this->api_base_url . $search_where . $first_subject_path . $is . "'" . $this->where_clause . "'" . $or . $second_subject_path . $is . "'" . $this->where_clause . "'" . $optional_param;  //add the base url, put it all together
		}

		// go and get it
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $this->url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
		$ok = curl_exec( $ch );

		if ( false == $ok ) {
			throw new \Exception( 'Sorry, something went wrong with the API call to SOLR. <p>Visit <b>https://open.bccampus.ca/find-open-textbooks/</b> to discover and download free textbooks.</p>' );
		}

		//get the array back from the API call
		$result = json_decode( $ok, true );

		//if the # of results we get back is less than the max we asked for
		if ( $result['length'] != 50 ) {

			$this->available_results     = $result['available'];
			$this->just_the_results_maam = $result['results'];
		} else {

			// is the available amount greater than the what was returned? Get more!
			$available_results = $result['available'];
			$start             = $result['start'];
			$limit             = $result['length'];

			if ( $available_results > $limit ) {
				$loop = intval( $available_results / $limit );

				for ( $i = 0; $i < $loop; $i ++ ) {
					$start        = $start + 50;
					$search_where = 'search?' . $any_query . '&collections=' . $this->collection_uuid . '&start=' . $start . '&length=' . $limit . '&order=' . $order . '&where=';   //length 50 is the max results allowed by the API
					//Three different scenarios here, depending..
					//1
					if ( ! empty( $this->where_clause ) && $this->by_contributor_flag == true ) {
						$this->url = $this->api_base_url . $search_where . $first_subject_path . $is . "'" . $this->where_clause . "'" . $optional_param;
					} //2
					elseif ( ! empty( $this->where_clause ) ) {
						$this->url = $this->api_base_url . $search_where . $first_subject_path . $is . "'" . $this->where_clause . "'" . $or . $second_subject_path . $is . "'" . $this->where_clause . "'" . $optional_param;  //add the base url, put it all together
					} //3
					else {
						$this->url = $this->api_base_url . $search_where . $optional_param;
					}
					// modify the url
					curl_setopt( $ch, CURLOPT_URL, $this->url );
					$ok2 = curl_exec( $ch );

					if ( false == $ok ) {
						throw new \Exception( 'Something went wrong with the API call to SOLR' );
					}

					$next_result = json_decode( $ok2, true );

					// push each new result onto the existing array
					$part_of_next_result = $next_result['results'];
					foreach ( $part_of_next_result as $val ) {
						array_push( $result['results'], $val );
					}
				}
			} /* end of if */
		} /* end of else */
		curl_close( $ch );

		$this->available_results     = $result['available'];
		$this->just_the_results_maam = $result['results'];
	}

	/**
	 * Helper function to turn an array into a comma separated value. If it's passed
	 * a key (mostly an author's name) it will strip out the equella user name
	 *
	 * @param array $any_array
	 * @param String $key - the key of the associative array you want returned
	 *
	 * @return String of comma separated values
	 */
	public static function arrayToCSV( $any_array = [], $key = '' ) {
		$result = '';

		if ( is_array( $any_array ) ) {
			//if it's not being passed a key from an associative array
			//NOTE adding a space to either side of the comma below will break the
			//integrity of the url given to get_file_contents above.
			if ( $key == '' ) {
				foreach ( $any_array as $value ) {
					$result .= $value . ',';
				}
				//return the value at the key in the associative array
			} else {
				foreach ( $any_array as $value ) {
					//names in db sometimes contain usernames [inbrackets], strip 'em out!
					$tmp     = ( ! strpos( $value[ $key ], '[' ) ) ? $value[ $key ] : rtrim( strstr( $value[ $key ], '[', true ) );
					$result .= $tmp . ', ';
				}
			}

			$result = rtrim( $result, ', ' );
		} else {
			return false;
		}

		return $result;
	}

}


