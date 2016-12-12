<?php

namespace Lagan;

/**
 * Search controller for Lagan.
 *
 * We use "*" as the seperator, since "-" is in the slug, and "+" is filtered out of the $_GET variable name by PHP.
 *
 * Syntax:
 * Value from: *min
 * Value to: *max
 * Contains: *has
 * Equal to: *is
 * Sort: sort
 * Limit: limit
 * Offset: offset
 *
 * offset only works if limit is defined too
 *
 * Query structure examples:
 * [model]?*has=[search string] :				Searches all searchable properties of a model
 * [model]?[property]*has=[search string] :		Searches single property of a model
 * [model]?[property]*min=[number]
 * sort=[property]*asc
 * [model]?description*title*has=[search string]&title*has=[search string]&sort=title*asc&offset=10&limit=100
 *
 * To be used with Lagan: https://github.com/lutsen/lagan
 */

class Search {

	protected $type;
	protected $criteria;
	protected $sequences;
	protected $model;

	/**
	 * Construct function
	 *
	 * @param string $type The Bean type to search
	 */
	function __construct($type) {

		$this->type = $type;

		$this->criteria = array(
			'*min',
			'*max',
			'*has',
			'*is'
		);

		// Sorting order
		$this->sequences = array(
			'*asc',
			'*desc'
		);

		$model_name = '\Lagan\Model\\' . ucfirst($type);
		$this->model = new $model_name;

	}

	/**
	 * Search function
	 *
	 * @param array[] $params Request parameters array
	 *
	 * @return array[] Array with ['reslut'] of Redbean beans matching the search criteria, ['total'] beans for the query, total ['pages'], current ['page'], ['offset'], ['limit'], ['query'] url part and ['section'] url part.
	 */
	public function find($params) {

		// Search
		$loop = 0; // To create different name for all search values
		$q = [];
		$s= [];
		$values = [];
		foreach ($params as $left => $right) {

			$lhs = $this->lefthandside($left);

			// Sort
			if ( $lhs == 'sort' ) {

				$rhs = $this->righthandside($right);

				$glue = ' '.strtoupper($rhs['order']).', ';
				$s[] = implode( $glue, $rhs['properties'] ) . ' ' . strtoupper($rhs['order']); // Add latest order

			} else if ($lhs === 'offset') {

				// Limit
				$offset = floatval($right);
				$values[ ':offset' ] = floatval($right);
			
			} elseif ($lhs === 'limit') {

				// Limit
				$limit = floatval($right);
				$values[ ':limit' ] = floatval($right);

			// Find
			} else if ( $lhs ) {

				$p = [];
				foreach ($lhs['properties'] as $k => $v) {

					if ( $this->isSearchable($v) ) {

						if ($lhs['criterion'] === '*min') {

							// Create '>=' query
							$p[] = ' '.$v.' >= :value'.$loop.' ';
							// Add value to Redbean named search values array
							$values[ ':value'.$loop ] = floatval($right);

						} elseif ($lhs['criterion'] === '*max') {

							// Create '<=' query
							$p[] = ' '.$v.' <= :value'.$loop.' ';
							// Add value to Redbean named search values array
							$values[ ':value'.$loop ] = floatval($right);

						} elseif ($lhs['criterion'] === '*has') {

							// Create 'LIKE' query
							$p[] = ' '.$v.' LIKE :value'.$loop.' ';
							// Add value to Redbean named search values array
							$values[ ':value'.$loop ] = '%'.$right.'%';

						} elseif ($lhs['criterion'] === '*is') {

							// Create '=' query
							$p[] = ' '.$v.' = :value'.$loop.' ';
							// Add value to Redbean named search values array
							$values[ ':value'.$loop ] = $right;

						}

					} else {
						throw new \Exception($v . ' is not searchable.');
					} // End isSearchable($v)

				} // End foreach $lhs['properties']


				// Implode array to create nice 'OR' query
				$q[] = implode('OR', $p);

				$loop++;

			} // End if else

		} // End foreach $params

		// Query

		// Implode array to create nice '( #query ) AND ( #query )'
		$query = '';
		if ( count($q) > 1 ) {
			$query = '(' . implode(') AND (', $q) . ')';
		} else if (count($q) > 0) {
			$query = $q[0];
		}

		// Implode different sort arrays
		$sort = '';
		if ( count($s) > 0 ) {
			$sort = ' ORDER BY ' . implode(', ', $s);
		}

		$part = '';
		if ( isset($limit) ) {
			$part = ' LIMIT :limit';
			if ( isset($offset) ) {
				$part .= ' OFFSET :offset';
			}
		} else {
			unset( $values[ ':limit' ] );
			unset( $values[ ':offset' ] );
		}

		// Search result
		$return['result'] = \R::find( $this->type, $query.$sort.$part, $values );

		// Total number of results for this query
		unset( $values[ ':limit' ] );
		unset( $values[ ':offset' ] );
		$return['total'] = \R::count( $this->type, $query.$sort, $values );

		// Pages
		if ( $limit ) {
			$return['limit'] = $limit;
			// Total pages
			$return['pages'] = ceil( $return['total'] / $limit );
			if ( $offset ) {
				$return['offset'] = $offset;
				// Current page
				$return['page'] = ceil( $offset / $limit );
			}
		}

		// Seperate search request from section request
		foreach ($params as $left => $right) {
			if ( $left == 'limit' || $left == 'offset' ) {
				$section .= '&'.$left.'='.$right;
			} else {
				$search .= '&'.$left.'='.$right;
			}
		}

		if ( $section ) {
			$return['section'] = substr( $section, 1);
		}
		if ( $search ) {
			$return['query'] = substr( $search, 1);
		}

		return $return;

	}

	/*
	 * Check if property exists and is searchable
	 *
	 * @param string $propertyname
	 *
	 * @return boolean
	 */
	private function isSearchable($propertyname) {
		foreach ($this->model->properties as $property) {
			if ( $property['name'] == $propertyname ) {
				if ( $property['searchable'] ) {
					return true;
				} else {
					return false;
				}
			}
		}
	}

	/*
	 * Analyse the left hand side of the search equation
	 *
	 * @param string $input
	 *
	 * @return string[] Array containing criterion and nested array with properties to search
	 */
	private function lefthandside($input) {
		if ($input == 'sort') { // Sorting happens after searching
			return 'sort';
		} else if ($input == 'offset') {
			return 'offset';
		} else if ($input == 'limit') {
			return 'limit';
		} else {
			foreach($this->criteria as $criterion) {
				if (substr($input, strlen($criterion)*-1) == $criterion) {
					$return = [ 'criterion'=>$criterion ];
					if (strlen($input) > strlen($criterion)) {
						$return['properties'] = explode('*', substr($input, 0, strlen($criterion)*-1)); // Array of properties
					} else {

						// If no properties are defined, return all searchable properties
						foreach ($this->model->properties as $property) {
							if ($property['searchable']) {
								$return['properties'][] = $property['name'];
							}
						}

						if ( count($return['properties']) == 0 ) {
							throw new \Exception('This model has no searchable properties.');
						}

					}
					return $return;
				}
			}
		}
		return false;
	}

	/*
	 * Analyse the right hand side of the search equation
	 *
	 * @param string $input
	 *
	 * @return string[] Array containing order and nested array with properties to sort by
	 */
	private function righthandside($input) {
		foreach($this->sequences as $order) {
			if (substr($input, strlen($order)*-1) == $order) {
				$return = [ 'order' => substr($order, 1) ];
				if (strlen($input) > strlen($order)) {
					$return['properties'] = explode('*', substr($input, 0, strlen($order)*-1)); // Array of properties
				} else {
					return false;
				}
				return $return;
			}
		}
		return false;
	}

}

?>