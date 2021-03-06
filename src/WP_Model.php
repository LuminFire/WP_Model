<?php

/**
 * WP_Model
 *
 * A simple class for creating active
 * record, eloquent-esque models of WordPress Posts.
 *
 * @author     AnthonyBudd <anthonybudd94@gmail.com>
 */
Abstract Class WP_Model implements JsonSerializable
{
	protected $tax_data = [];
	protected $data     = [];
	public $ID;
	public $_post;
	public $_where;
	public $title;
	public $content;

	public $attributes = [];
	public $prefix = '';

	public $taxonomies = [];
	public $default = [];
	public $virtual = [];
	public $filter = [];

	public $new    = TRUE;
	public $dirty  = FALSE;
	public $booted = FALSE;

	

	/**
	 * Create a new instace with data
	 * 
	 * @param array $insert
	 * @return void
	 */
	public function __construct(Array $insert = [])
	{ 	
		if(!empty($this->default)){
			foreach($this->default as $attribute => $value){
				$this->data[$attribute] = $value;
			}
		}

		foreach($insert as $attribute => $value){
			if(in_array($attribute, $this->attributes)){
				$this->set($attribute, $value);
			}
			
			if(!empty($this->taxonomies)){
				if(in_array($attribute, $this->taxonomies)){
					if(is_array($value)){
						$this->addTaxonomies($attribute, $value);
					}else{
						$this->addTaxonomy($attribute, $value);
					}
				}
			}
		}

		if(!empty($insert['title'])){
			$this->title = $insert['title'];
		}

		if(!empty($insert['content'])){
			$this->content = $insert['content'];
		}

		$this->boot();
	}

	/**
	 * Initalize the model, load in any addional data
	 *
	 * @return void
	 */
	protected function boot()
	{
		$this->triggerEvent('booting');

		if(!empty($this->ID)){
			$this->new = FALSE;
			$this->_post = get_post($this->ID);
			$this->title = $this->_post->post_title;
			$this->content = $this->_post->post_content;

			foreach($this->attributes as $attribute){
				$meta = $this->getMeta($attribute);
				if(empty($meta) && isset($this->default[$attribute])){
					$this->set($attribute, $this->default[$attribute]);
				}else{
					$this->set($attribute, $meta);
				}
			}

			if(!empty($this->taxonomies)){
				foreach($this->taxonomies as $taxonomy){
					$this->tax_data[$taxonomy] = get_the_terms($this->ID, $taxonomy);

					if($this->tax_data[$taxonomy] === FALSE){
						$this->tax_data[$taxonomy] = [];
					}
				}
			}
		}

		$this->booted = TRUE;
		$this->triggerEvent('booted');
	}

	/**
	 * Register the post type using the propery $postType as the post type
	 * 
	 * @param  array  $args  see: register_post_type()
	 * @return void
	 */
	public static function register($args = [])
	{
		$postType = Self::getPostType();

		$defualts = [
			'public' => TRUE,
			'label' => ucfirst($postType)
		];

		register_post_type($postType, array_merge($defualts, $args));

		Self::addHooks();
	}

	/**
	 * Create a new model with data, save and return the model
	 * 
	 * @param array $insert
	 */
	public static function insert(Array $insert = []){
		return Self::newInstance($insert)->save();
	}


	// -----------------------------------------------------
	// EVENTS
	// -----------------------------------------------------
	/**
	 * Fire event if the event method exists
	 * 
	 * @param  string $event event name
	 * @return bool
	 */
	protected function triggerEvent($event)
	{
		if(method_exists($this, $event)){
			$this->$event($this);
			return TRUE;
		}

		return FALSE;
	}
	

	// -----------------------------------------------------
	// HOOKS
	// -----------------------------------------------------
	/**
	 * Add hooks
	 *
	 * @return void
	 */
	public static function addHooks()
	{
		add_action(('save_post'), [get_called_class(), 'onSave'], 9999999999);
	}
 
 	/**
	 * Remove hooks
	 *
	 * @return void
	 */
	public static function removeHooks()
	{
		remove_action(('save_post'), [get_called_class(), 'onSave'], 9999999999);
	}

	/**
	 * save_post hook: Triggers save method for a given post
	 * 
	 * @param int $ID
	 * @return void
	 */
	public static function onSave($ID)
	{
		if(get_post_status($ID) == 'publish' &&
			Self::exists($ID)){ // If post is the right post type
			$post = Self::find($ID);
			$post->save();
		}
	}


	// -----------------------------------------------------
	// UTILITY METHODS
	// -----------------------------------------------------
	/**
	 * Create a new model without calling the constructor.
	 * 
	 * @return object
	 */
	protected static function newWithoutConstructor(){
		$class = get_called_class();
		$reflection = new ReflectionClass($class);
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Returns the post type
	 * 
	 * @return string
	 *
	 * @throws \Exception
	 */
	public static function getPostType()
	{
		$model = Self::newWithoutConstructor();

		if(isset($model->postType)){
			return $model->postType;
		}elseif(isset($model->name)){
			return $model->name;
		}

		throw new Exception('$postType not defined');
	}

	/**
	 * Returns a new model
	 * 
	 * @return object
	 */
	public static function newInstance($insert = [])
	{
		$class = get_called_class();
		return new $class($insert);
	}

	/**
	 * Returns an array representaion of the model for serialization 
	 * 
	 * @return array
	 */
	public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Returns TRUE if $attribute is in the $virtual array 
     * and has a corresponding vitaul property method
     *
     * @param  string $attribute
	 * @return bool
	 */
	public function isVirtualProperty($attribute)
	{
		return (isset($this->virtual) &&
			in_array($attribute, $this->virtual) && 
			method_exists($this, ('_get'. ucfirst($attribute))));
	}

	/**
	 * Calls virtual property method
	 *
	 * @param  string $attribute
	 * @return mixed
	 */
	public function getVirtualProperty($attribute)
	{
		return call_user_func([$this, ('_get'. ucfirst($attribute))]);  
	}

	/**
     * Returns TRUE if $attribute is in the $filter array 
     * and has a corresponding filter property method
     *
     * @param  string $attribute
	 * @return bool
	 */
	public function isFilterProperty($attribute)
	{
		return (isset($this->filter) &&
			in_array($attribute, $this->filter) &&
			method_exists($this, ('_filter'. ucfirst($attribute))));
	}

	/**
	 * Calls filter property method
	 *
	 * @param  string $attribute
	 * @return mixed
	 */
	public function getFilterProperty($attribute)
	{
		return call_user_func_array([$this, ('_filter'. ucfirst($attribute))], [$this->get($attribute)]);  
	}


	// -----------------------------------------------------
	// Meta
	// -----------------------------------------------------
	/**
	 * Returns meta value for a meta key
	 * 
	 * @param  string meta_key
	 * @return string
	 */
    public function getMeta($key){
		return get_post_meta($this->ID, ($this->prefix.$key), TRUE);
	}

	/**
	 * Set meta value for a meta key
	 * 
	 * @param  string meta_key
	 * @param  string meta_value
	 * @return void
	 */
	public function setMeta($key, $value){
		update_post_meta($this->ID, ($this->prefix.$key), $value);
	}

	/**
	 * Delete meta's meta 
	 * 
	 * @param  string meta_key
	 * @return void
	 */
	public function deleteMeta($key){
		delete_post_meta($this->ID, ($this->prefix.$key));
	}


    // -----------------------------------------------------
	// GETTERS & SETTERS
	// -----------------------------------------------------
	/**
	 * Get property of model or $default
	 * 
	 * @param  property $attribute [description]
	 * @param  property $default
	 * @return mixed
	 *
	 * @todo  investagte this method
	 */
	public function get($attribute, $default = NULL)
	{
		if(isset($this->data[$attribute])){
			return $this->data[$attribute];
		}

		return $default;
	}

	/**
	 * Set propert of the model
	 * 
	 * @param string $attribute
	 * @param string $value
	 * @return void
	 */
	public function set($attribute, $value)
	{
		if(in_array($attribute, $this->attributes)){
			$this->data[$attribute] = $value;
		}
	}

	// -----------------------------------------------------
	// MAGIC METHODS
	// -----------------------------------------------------
	/**
	 * @return void
	 */
	public function __set($attribute, $value)
	{
		if($this->booted){
			$this->dirty = true;
		}

		if(in_array($attribute, $this->attributes)){
			$this->set($attribute, $value);
		}else if(isset($this->taxonomies) && in_array($attribute, $this->taxonomies)){
			$this->setTaxonomy($attribute, $value);
		}
	}

	/**
	 * @return void
	 */
	public function __get($attribute)
	{
		if(in_array($attribute, $this->attributes)){
			if($this->isFilterProperty($attribute)){
				return $this->getFilterProperty($attribute);
			}
			return $this->get($attribute);
		}else if($this->isVirtualProperty($attribute)){
			return $this->getVirtualProperty($attribute);
		}else if(isset($this->taxonomies) && in_array($attribute, $this->taxonomies)){
			return $this->getTaxonomy($attribute, 'name');
		}else if($attribute === 'post_title'){
			return $this->title;
		}else if($attribute === 'post_content'){
			return $this->content;
		}
	}

	// -----------------------------------------------------
	// Taxonomies
	// -----------------------------------------------------
	/**
	 * 
	 * @return void
	 */
	public function getTaxonomy($attribute, $param = NULL)
	{
		if(isset($this->taxonomies) && isset($this->tax_data[$attribute])){
			return array_map(function($tax) use ($param){
				if(!is_null($param)){
					return $tax->$param;
				}

				return $tax;
			}, $this->tax_data[$attribute]);
		}

		return [];
	}

	/**
	 * @return void
	 */
	public function addTaxonomy($taxonomy, $value)
	{
		$term;
		if(is_int($value)){
			$term = get_term_by('id', $value, $taxonomy);
		}elseif(is_string($value)){
			$term = get_term_by('slug', $value, $taxonomy);
		}

		if(!empty($term)){
			if(empty($this->tax_data[$taxonomy])){
				$this->tax_data[$taxonomy] = [];
			}

			$this->tax_data[$taxonomy][] = $term;
		}else{
			return FALSE;
		}
	}

	/**
	 * @return void
	 */
	public function addTaxonomies($attribute, Array $taxonomies)
	{
		$this->tax_data[$attribute] = [];
		foreach($taxonomies as $taxonomy){
			$this->addTaxonomy($attribute, $taxonomy);
		}
	}

	/**
	 * @return void
	 */
	public function removeTaxonomy($attribute, $value)
	{
		$taxonomies = [];
		if(!empty($this->tax_data[$attribute])){
			foreach($this->tax_data[$attribute] as $tax){
				if(is_int($value)){
					if($tax->term_id !== $value){
						$taxonomies[] = $tax;
					}
				}elseif(is_string($value)){
					if($tax->slug !== $value){
						$taxonomies[] = $tax;
					}
				}
			}

			$this->tax_data[$attribute] = $taxonomies;
		}
	}

	/**
	 * @return void
	 */
	public function removeTaxonomies($attribute, Array $taxonomies)
	{
		foreach($taxonomies as $taxonomy){
			$this->removeTaxonomy($attribute, $taxonomy);
		}
	}

	/**
	 * @return void
	 */
	public function clearTaxonomy($taxonomy){
		$this->addTaxonomies($taxonomy, []);
	}

	// -----------------------------------------------------
	// HELPER METHODS
	// -----------------------------------------------------
	/**
	 * Check if the post exists by Post ID
	 * 
	 * @param  string|inte  $ID   Post ID
	 * @param  bool 		$postTypeSafe Require post to be the same post type as the model
	 * @return bool
	 */
	public static function exists($ID, $postTypeSafe = TRUE)
	{	
		if($postTypeSafe){
			if(
				(get_post_status($ID) !== FALSE) &&
				(get_post_type($ID) == Self::getPostType())){
				return TRUE;
			}
		}else{
			return (get_post_status($ID) !== FALSE);
		}

		return FALSE;
	}

	/**
	 * Returns the original post object
	 * 
	 * @return WP_Post
	 */
	public function post()
	{
		return $this->_post;
	}

	/**
	 * Returns TRUE if the model's post has an associated featured image
	 * 
	 * @return bool
	 */
	public function hasFeaturedImage()
	{
		return (get_the_post_thumbnail_url($this->ID) !== FALSE)? TRUE : FALSE;
	}

	/**
	 * Get model's featured image or return $default if it does not exist
	 * 
	 * @param  string $default
	 * @return string
	 */
	public function featuredImage($default = '')
	{
		$featuredImage = get_the_post_thumbnail_url($this->ID);
		return ($featuredImage !== FALSE)? $featuredImage : $default;
	}

	/**
	 * Returns an asoc array representaion of the model
	 * 
	 * @return array
	 */
	public function toArray()
	{
		$model = [];

		foreach($this->attributes as $key => $attribute){
			if(!empty($this->protected) && !in_array($attribute, $this->protected)){
				// Do not add to $model
			}else{
				$model[$attribute] = $this->get($attribute);
			}
		}

		if(!empty($this->serialize)){
			foreach($this->serialize as $key => $attribute){
				if(!empty($this->protected) && !in_array($attribute, $this->protected)){
					// Do not add to $model
				}else{
					$model[$attribute] = $this->$attribute;
				}
			}
		}

		$model['ID'] = $this->ID;
		$model['title'] = $this->title;
		$model['content'] = $this->content;

		return $model;
	}

	/**
	 * Get the model for a single page or in the loop
	 * 
	 * @return object|NULL
	 */
	public static function single()
	{
		return Self::find(get_the_ID());
	}

	/**
	 * returns the post's permalink
	 * 
	 * @return string
	 */
	public function permalink()
	{
		return get_permalink($this->ID);
	}


	// ----------------------------------------------------
	// FINDERS
	// ----------------------------------------------------
	/**
	 * Find model by it's post ID
	 * 
	 * @param  int $ID
	 * @return Object|NULL
	 */
	public static function find($ID)
	{
		if(Self::exists($ID)){
			$class = Self::newInstance();
			$class->ID = $ID;
			$class->boot();
			return $class;
		}

		return NULL;
	}

	/**
	 * Get model by ID without booting the model
	 * 
	 * @param  int $ID
	 * @return Object|NULL
	 */
	public static function findBypassBoot($ID)
	{
		if(Self::exists($ID)){
			$class = Self::newInstance();
			$class->ID = $ID;
			return $class;
		}

		return NULL;
	}

	/**
	 * Find the model by ID. If the post does not exist throw.
	 * 
	 * @param  int $id
	 * @return object
	 *
	 * @throws  \Exception
	 */
	public static function findOrFail($ID)
	{
		if(!Self::exists($ID)){
			throw new Exception("Post {$ID} not found");
		}

		return Self::find($ID);
	}

	/**
	 * Returns all models
	 * 
	 * @param  string $limit
	 * @return array
	 */
	public static function all($limit = '999999999')
	{
		$return = [];
		$args = [
			'post_type' 	 => Self::getPostType(),
			'posts_per_page' => $limit,
			'order'          => 'DESC',
			'orderby'        => 'id',
		];

		foreach((new WP_Query($args))->get_posts() as $post){
			$return[] = Self::find($post->ID);
		}

		return $return;
	}

	/**
	 * Retun an array of models as asoc array. Key by $value
	 * 
	 * @param  string  $value
	 * @param  array   $models
	 * @return array
	 */
	public static function asList($value = 'title', $models = FALSE)
	{
		if(!is_array($models)){
			$self = get_called_class();
			$models = $self::all();
		}

		$return = [];
		foreach($models as $model){
			if(is_int($model) || $model instanceof WP_Post){
				$model = Self::find($model->ID);
			}
			
			$return[$model->ID] = $model->$value;
		}

		return $return;
	}

	/**
	 * Execute funder method
	 *
	 * @param  string $finder
	 * @param  array $arguments
	 * @return array
	 */
	public static function finder($finder, Array $arguments = [])
	{
		$return = [];
		$finderMethod = '_finder'.ucfirst($finder);
		$class = get_called_class();
		$model = $class::newWithoutConstructor();
		if(!in_array($finderMethod, array_column(( new ReflectionClass(get_called_class()) )->getMethods(), 'name'))){
			throw new Exception("Finder method {$finderMethod} not found in {$class}");
		}

		$args = $model->$finderMethod($arguments);
		if(!is_array($args)){
			throw new Exception("Finder method must return an array");
		}

		$args['post_type'] = Self::getPostType();
		foreach((new WP_Query($args))->get_posts() as $key => $post){
			$return[] = Self::find($post->ID);
		}

		$postFinderMethod = '_postFinder'.ucfirst($finder);
		if(in_array($postFinderMethod, array_column(( new ReflectionClass(get_called_class()) )->getMethods(), 'name'))){
			return $model->$postFinderMethod($return, $arguments);
		}

		return $return;
	}

	/**
	 * @return void
	 */
	public static function where($key, $value = FALSE)
	{
		if(is_array($key)){
			$params = [
				'post_type'  => Self::getPostType(),
				'meta_query' => [],
				'tax_query'  => [],
			];

			foreach($key as $key_ => $meta){
				if($key_ === 'meta_relation'){
					$params['meta_query']['relation'] = $meta;
				}else if($key_ === 'tax_relation'){
					$params['tax_query']['relation'] = $meta;
				}else if(!empty($meta['taxonomy'])){
					$params['tax_query'][] = [
						'taxonomy' => $meta['taxonomy'],
                		'field'    => isset($meta['field'])? $meta['field'] : 'slug',
                		'terms'    => $meta['terms'],
                		'operator' => isset($meta['operator'])? $meta['operator'] : 'IN',
					];
				}else{
					$params['meta_query'][] = [
						'key'       => isset($meta['key'])? $meta['key'] : $meta['meta_key'],
						'value'     => isset($meta['value'])? $meta['value'] : $meta['meta_value'],
						'compare'   => isset($meta['compare'])? $meta['compare'] : '=',
						'type'      => isset($meta['type'])? $meta['type'] : 'CHAR'
					];
				}
			}

			$query = new WP_Query($params);
		}else{
			$query = new WP_Query([
				'post_type' 		=> Self::getPostType(),
				'meta_query'        => [
					[
						'key'       => $key,
						'value'     => $value,
						'compare'   => '=',
					],
				]
			]);
		}

		$arr = [];
		foreach($query->get_posts() as $key => $post){
			$arr[] = Self::find($post->ID); 
		}

		return $arr;
	}

	/**
	 * @return void
	 */
	public static function in($ids = [])
	{
		$results = [];
		if(!is_array($ids)){
			$ids = func_get_args();
		}

		foreach($ids as $key => $id){
			if(Self::exists($id)){
				$results[] = Self::find($id); 
			}
		}

		return $results;
	}

	// -----------------------------------------------------
	// Query
	// -----------------------------------------------------
	public static function query(){
		$model = Self::newWithoutConstructor();
		$model->_where = [];
		return $model;
	}

	public function meta($key, $x, $y = NULL, $z = NULL){
		if(!is_null($z)){
			$this->_where[] = [
				'key'     => $key,
				'compare' => $x,
				'value'   => $y,
				'type'    => $z
			];
		}elseif(!is_null($y)){
			$this->_where[] = [
				'key'     => $key,
				'compare' => $x,
				'value'   => $y,
			];
		}else{
			$this->_where[] = [
				'key'     => $key,
				'value'   => $x,
			];
		}

		return $this;
	}

	public function tax($taxonomy, $x, $y = NULL, $z = NULL){
		if(!is_null($z)){
			$this->_where[] = [
				'taxonomy'  => $taxonomy,
				'field'     => $x,
				'operator'  => $y,
				'terms'     => $z,
			];
		}elseif(!is_null($y)){
			$this->_where[] = [
				'taxonomy'  => $taxonomy,
				'field'     => $x,
				'operator'  => 'IN',
				'terms'     => $y,
			];
		}else{
			$this->_where[] = [
				'taxonomy'  => $taxonomy,
				'field'     => 'slug',
				'operator'  => 'IN',
				'terms'     => $x,
			];
		}

		return $this;
	}

	public function execute(){
		return Self::where($this->_where);
	}

	public function executeAsoc($key){
		$models = Self::where($this->_where);
		return Self::asList($key, $models);
	}

	// -----------------------------------------------------
	// SAVE
	// -----------------------------------------------------
	/**
	 * Save the model and all of it's asociated data
	 * @return Object $this
	 */
	public function save()
	{
		$this->triggerEvent('saving');

		$overwrite = [
			'post_type' => Self::getPostType()
		];

		Self::removeHooks();

		if(is_integer($this->ID)){
			$defualts = [
				'ID'           => $this->ID,
				'post_title'   => $this->title,
				'post_content' => ($this->content !== NULL)? $this->content :  ' ',
			];

			wp_update_post(array_merge($defualts, $overwrite));
		}else{
			$this->triggerEvent('inserting');
			$defualts = [
				'post_status'  => 'publish',
				'post_title'   => $this->title,
				'post_content' => ($this->content !== NULL)? $this->content :  ' ',
			];

			$this->ID = wp_insert_post(array_merge($defualts, $overwrite));
			$this->_post = get_post($this->ID);
			$this->triggerEvent('inserted');
		}

		Self::addHooks();

		if(!empty($this->taxonomies)){
			foreach($this->taxonomies as $taxonomy) {
				wp_set_post_terms($this->ID, $this->getTaxonomy($taxonomy, 'term_id'), $taxonomy);
			}
		}

		foreach($this->attributes as $attribute){
			$this->setMeta($attribute, $this->get($attribute, ''));
		}	

		$this->setMeta('_id', $this->ID);
		$this->triggerEvent('saved');
		$this->dirty = FALSE;
		$this->new = FALSE;
		return $this;
	}

	// -----------------------------------------------------
	// DELETE
	// -----------------------------------------------------
	/**
	 * @return void
	 */
	public function delete()
	{
		$this->triggerEvent('deleting');
		wp_trash_post($this->ID);
		$this->triggerEvent('deleted');
	}

	/**
	 * @return void
	 */
	public function hardDelete()
	{
		$this->triggerEvent('hardDeleting');

		$defualts = [
			'ID'           => $this->ID,
			'post_title'   => '',
			'post_content' => '',
		];

		wp_update_post($defualts);

		foreach($this->attributes as $attribute){
			$this->deleteMeta($attribute);
			$this->set($attribute, NULL);
		}

		$this->setMeta('_id', $this->ID);
		$this->setMeta('_hardDeleted', '1');
		wp_delete_post($this->ID, TRUE);
		$this->triggerEvent('hardDeleted');
	}

	/**
	 * @return void
	 */
	public static function restore($ID){
		wp_untrash_post($ID);
		return Self::find($ID);
	}
}