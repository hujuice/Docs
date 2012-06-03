<?php
/**
 * Interrogazioni al DB di Wordpress
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package     Docs
 * @copyright   Copyright (c) 2012 Sergio Vaccaro <hujuice@inservibile.org>
 * @license     http://www.gnu.org/licenses/gpl-3.0.txt     GPLv3
 * @version     1.0
 */
namespace Docs;

/**
 * Unified API to query www.istat.it docs
 *
 * Unified front-end for the retriving of informations from the database
 * of the www.istat.it documents.
 *
 * @package     Docs
 * @copyright   Copyright (c) 2012 Sergio Vaccaro <hujuice@inservibile.org>
 * @license     http://www.gnu.org/licenses/gpl-3.0.txt     GPLv3
 */
class Docs
{
    /**
     * The database
     * @var PDO
     */
    protected $_db;

    /**
     * Runa query in a statement and gives a fetchAll(\PDO::FETCH_ASSOC) result
     *
     * This function is intended mainly to manage the excpetions
     * @param PDOStatement $statement The already prepared statement
     * @return array The fetchAll(\PDO::FETCH_ASSOC) result
     * @throw PDOException Gives the error messasge
     */
    protected function _execute(\PDOStatement $statement)
    {
        if ($statement->execute())
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        else
        {
            //$statement->debugDumpParams();
            //exit;
            $error = $statement->errorInfo();
            throw new \PDOException($error[2]);
        }
    }

    /**
     * Filter the children pages from a parent pages list
     *
     * This function is needed because it is recoursive
     *
     * @param array $parents Parent pages
     * @return array The nested array of children
     * @throw Exception The exception will be rised on malformed input data
     */
    protected function _menuChildren($parents)
    {
        if (is_array($parents))
        {
            if ($parents)
            {
                $bindings = array();
                $count = 1;
                foreach ($parents as $parent)
                {
                    $bindings['id' . $count] = array('value' => (integer) $parent['id'], 'type' => \PDO::PARAM_INT);
                    $count++;
                }

                $query = 'SELECT www_posts.ID as id, www_posts.post_title as title, www_posts.post_parent as parent, www_postmeta.meta_key, www_postmeta.meta_value as label
                            FROM www_posts
                                LEFT JOIN www_postmeta ON www_postmeta.post_id = www_posts.ID
                            WHERE www_posts.post_status = :status
                                AND www_posts.post_type = :type
                                AND www_posts.post_parent IN (:' . implode(',:', array_keys($bindings)) . ')
                            ORDER BY www_posts.post_parent, www_posts.menu_order';
                $statement = $this->_db->prepare($query);

                $statement->bindValue('status', 'publish');
                $statement->bindValue('type', 'page');
                foreach ($bindings as $name => $param)
                    $statement->bindValue($name, $param['value'], $param['type']);

                $children = $this->_execute($statement);
                foreach ($parents as & $parent)
                {
                    $pages = array();
                    foreach ($children as & $child)
                    {
                        if ($child['parent'] == $parent['id'])
                        {
                            if (!isset($pages[$child['id']]))
                                $pages[$child['id']] = array('id' => $child['id']);

                            if (('titolobreve' == $child['meta_key']) && $child['label'])
                                $pages[$child['id']]['label'] = $child['label'];
                            else if (!isset($pages[$child['id']]['label']))
                                $pages[$child['id']]['label'] = $child['title'];

                            $child = null;
                        }
                    }
                    $children = array_filter($children); // Pulizia
                    foreach ($pages as & $page)
                        $page['pages'] = array();
                    $parent['pages'] = array_values($pages);

                    if ($parent['pages'])
                        $parent['pages'] = $this->_menuChildren($parent['pages']);
                }
            }

            return $parents;
        }
        else
            throw new \Exception('Parent pages MUST be an array.');
    }

    /**
     * Return a post list, given an ID list
     *
     * This function can be used either for a single post (one ID)
     * or for a list of posts.
     * The $full parameter determines whether the given informations
     * will be complete (e.g. for the single post) or light (e.g. for a list).
     * The $full = true mode is slower and should be used only if really
     * needed (i.e. for the single post).
     *
     * @param array $ids The IDs array
     * @param boolean $full Determines the light or full mode
     * @return array The list fo posts
     */
    protected function _posts($ids, $full = false)
    {
        // Input casting
        // ================================
        $ids = (array) $ids;
        $full = (boolean) $full;

        if ($ids)
        {
            // List binding (will be useful many times later)
            // ================================
            $bindings = array();
            $count = 1;
            foreach ($ids as $id)
            {
                $bindings['id' . $count] = array('value' => (integer) $id, 'type' => \PDO::PARAM_INT);
                $count++;
            }
            $in = ':' . implode(',:', array_keys($bindings));

            // Basic informations
            // ================================
            $fields = 'www_posts.ID as id, www_posts.post_type, UNIX_TIMESTAMP(www_posts.post_date_gmt) as created, UNIX_TIMESTAMP(www_posts.post_modified_gmt) as modified, www_icl_translations.language_code as lang, www_posts.post_title as title';
            if ($full)
                $fields .= ', www_posts.post_content as body';
            $query = 'SELECT ' . $fields . '
                        FROM www_posts
                            LEFT JOIN www_icl_translations ON www_icl_translations.element_id = www_posts.ID
                        WHERE (www_posts.post_type = :post OR www_posts.post_type = :page)
                            AND ID IN (' . $in . ')
                        ORDER BY menu_order ASC, post_date_gmt DESC'; // ORDER again, to avoid order mess up

            $statement = $this->_db->prepare($query);
            $statement->bindValue('post', 'post');
            $statement->bindValue('page', 'page');
            foreach ($bindings as $name => $param)
                $statement->bindValue($name, $param['value'], $param['type']);

            $list = $this->_execute($statement);

            // Categories
            // ================================
            $query = 'SELECT www_term_relationships.object_id as id, www_term_relationships.term_taxonomy_id as category_id, www_terms.name as label, www_term_taxonomy.taxonomy as type, www_categories.label as category
                        FROM www_term_relationships
                            LEFT JOIN www_terms ON www_terms.term_id = www_term_relationships.term_taxonomy_id
                            LEFT JOIN www_term_taxonomy ON www_term_taxonomy.term_taxonomy_id = www_term_relationships.term_taxonomy_id
                            LEFT JOIN www_categories ON www_categories.term_id = www_term_taxonomy.parent
                        WHERE (www_term_taxonomy.taxonomy = :taxonomy OR www_categories.label IS NOT NULL)
                            AND www_terms.term_id NOT IN (SELECT term_id FROM www_blacklisted_tags)
                            AND www_term_relationships.object_id IN (' . $in . ')
                        ORDER BY www_term_relationships.term_order';
            $statement = $this->_db->prepare($query);
            foreach ($bindings as $name => $param)
                $statement->bindValue($name, $param['value'], $param['type']);
            $statement->bindValue('taxonomy', 'post_tag');
            $info = $this->_execute($statement);

            // Better re-assemble this structure
            $categories = array();
            // Empty container
            foreach ($ids as $id)
                $categories[$id] = array('types' => array(), 'themes' => array(), 'regions' => array(), 'tags' => array());
            foreach ($info as $item)
            {
                if ('post_tag' == $item['type'])
                    $categories[$item['id']]['tags'][] = $item['label'];
                else
                    $categories[$item['id']][$item['category']][] = $item['category_id'];
            }
            // Garbage collection...
            unset($info);

            // Metainformations
            // ================================
            $query = 'SELECT post_id as id, meta_key, meta_value
                        FROM www_postmeta
                        WHERE post_id IN (' . $in . ')';
            $statement = $this->_db->prepare($query);
            foreach ($bindings as $name => $param)
                $statement->bindValue($name, $param['value'], $param['type']);
            $info = $this->_execute($statement);

            // Better re-assemble this structure
            $meta = array();
            foreach ($info as $item)
            {
                if (!isset($meta[$item['id']]))
                    $meta[$item['id']] = array();

                $meta[$item['id']][$item['meta_key']] = $item['meta_value'];
            }
            // Garbage collection...
            unset($info);

            // Mounting
            foreach ($list as & $post)
            {
                $post['shortTitle'] = empty($meta[$post['id']]['titolobreve'])
                                        ? ''
                                        : $meta[$post['id']]['titolobreve'];

                $post['subTitle'] = empty($meta[$post['id']]['sottotitolo'])
                                        ? ''
                                        : $meta[$post['id']]['sottotitolo'];

                $post['period'] = empty($meta[$post['id']]['descrizioneperiodo'])
                                        ? ''
                                        : $meta[$post['id']]['descrizioneperiodo'];

                $post['description'] = empty($meta[$post['id']]['news'])
                                        ? ''
                                        : $meta[$post['id']]['news'];

                $post['abstract'] = empty($meta[$post['id']]['news_rss'])
                                        ? ''
                                        : $meta[$post['id']]['news_rss'];

                /*
                Non conventional format... (aaaammgg)
                $post['dateOfIssue'] = isset($meta[$post['id']]['data_pubblicazione'])
                                        ? $meta[$post['id']]['data_pubblicazione']
                                        : $post['modified'];
                */
                if (!empty($meta[$post['id']]['data_pubblicazione']))
                {
                    $date = sscanf($meta[$post['id']]['data_pubblicazione'], '%04d%02d%02d', $Y, $m, $d); // Y, m, d, see date()
                    $post['created'] = mktime(9, 30, 0, (integer) $m, (integer) $d, (integer) $Y);
                }

                /*
                if (empty($meta[$post['id']]['data_pubblicazione']))
                    $post['dateOfIssue'] = $post['modified'];
                else
                {
                    $date = sscanf($meta[$post['id']]['data_pubblicazione'], '%04d%02d%02d', $Y, $m, $d); // Y, m, d, see date()
                    $post['dateOfIssue'] = mktime(9, 30, 0, (integer) $m, (integer) $d, (integer) $Y);
                }
                */

                $post['image'] = isset($meta[$post['id']]['image'])
                                        ? $meta[$post['id']]['image']
                                        : '';

                $post['types'] = $categories[$post['id']]['types'];
                $post['themes'] = $categories[$post['id']]['themes'];
                $post['regions'] = $categories[$post['id']]['regions'];
                $post['tags'] = $categories[$post['id']]['tags'];

                // Combine three datetimes in two datetimes
                // 'created', 'modified', 'dateOfIssue'
                /*
                Arbitrary logic: abort...
                if ($post['created'] < $post['dateOfIssue'])
                    $post['created'] = $post['dateOfIssue'];
                else
                {
                    // Created MUST be same day of dateOfIssue
                }
                */
                /*
                $post['created'] = $post['dateOfIssue'];

                if ($post['modified'] < $post['created'])
                    $post['modifed'] = $post['created'];
                unset($post['dateOfIssue']);
                */

                if ($full)
                {
                    // From now, all the query will be repeated for each post
                    // (that's why the full mode is slower)

                    // Translations
                    $post['translations'] = array();
                    $query = 'SELECT www_posts.post_title as title, www_icl_translations.language_code as lang, www_icl_translations.element_id as id, www_postmeta.meta_key, www_postmeta.meta_value as label
                                FROM www_posts
                                    LEFT JOIN www_icl_translations ON www_icl_translations.element_id = www_posts.ID
                                    LEFT JOIN www_postmeta ON www_postmeta.post_id = www_posts.ID
                                WHERE www_icl_translations.trid = (SELECT trid FROM www_icl_translations WHERE element_id = :post_id)
                                    AND www_icl_translations.language_code <> :lang';
                    $statement = $this->_db->prepare($query);
                    $statement->bindValue('post_id', $post['id'], \PDO::PARAM_INT);
                    $statement->bindValue('lang', $post['lang']);
                    foreach ($result = $this->_execute($statement) as $translation)
                    {
                        if (!isset($post['translations'][$translation['lang']]))
                            $post['translations'][$translation['lang']] = array('id' => $translation['id']);

                        if (('titolobreve' == $translation['meta_key']) && $translation['label'])
                            $post['translations'][$translation['lang']]['label'] = $translation['label'];
                        else if (!isset($post['translations'][$translation['lang']]['label']))
                            $post['translations'][$translation['lang']]['label'] = $translation['title'];
                    }

                    $post['attachments'] = array();
                    $post['boxes'] = array();
                    if ('post' == $post['post_type'])
                    {
                        // Attachments
                        $query = 'SELECT post_title as label, post_mime_type as mime_type, guid as url
                                    FROM www_posts
                                    WHERE post_type = :post_type
                                        AND www_posts.post_parent = :post_parent
                                    ORDER BY :order';
                        $statement = $this->_db->prepare($query);
                        $statement->bindValue('post_type', 'attachment');
                        $statement->bindValue('post_parent', $post['id'], \PDO::PARAM_INT);
                        $statement->bindValue('order', 'menu_order');

                        foreach ($this->_execute($statement) as $attachment)
                        {
                            // NO NO NO!!! Paths should not stay here...
                            $baseUrl = 'http://docs.istat.it/www/wp-content/uploads';
                            $attachment['url'] = str_replace($baseUrl, '', $attachment['url']);
                            // BOH!!!!!!!!!!!! da fare in ufficio
                            $basePath = __FILE__ . $attachment['url'];
                            if ($size = @filesize($basePath . $attachment['url']))
                                $attachment['size'] = $size;
                            else
                                $attachment['size'] = '';

                            $post['attachments'][] = $attachment;
                        }

                        // Boxes
                        if (isset($meta[$post['id']]['docs_linkedSideposts']))
                        {
                            // Read and assemble the serialized value
                            $box_bindings = array(); // Servirà più avanti
                            $count = 1;
                            if ($boxes = unserialize($meta[$post['id']]['docs_linkedSideposts']))
                            {
                                foreach ($boxes as $title => $box)
                                {
                                    // Very strange!
                                    $box_id = (integer) array_shift($box);

                                    // Horrible association
                                    $titles = array(
                                                    'contatti'         => 'Contatti',
                                                    'contatti (en)'    => 'Contacts',
                                                    );
                                    if (isset($titles[$title]))
                                        $title = $titles[$title];

                                    $post['boxes'][$box_id] = array('title' => $title); // It lacks the 'body'

                                    $box_bindings['box' . $count] = array('value' => $box_id, 'type' => \PDO::PARAM_INT);
                                    $count++;
                                }

                                $query = 'SELECT ID, post_content as body FROM www_posts
                                            WHERE post_status = :post_status
                                                AND ID IN (:' . implode(',:', array_keys($box_bindings)) . ')
                                            ORDER BY menu_order';
                                $statement = $this->_db->prepare($query);
                                foreach ($box_bindings as $name => $param)
                                    $statement->bindValue($name, $param['value'], $param['type']);
                                $statement->bindValue('post_status', 'publish');

                                foreach ($this->_execute($statement) as $content)
                                    $post['boxes'][$content['ID']]['body'] = $content['body'];

                                // Pulizia
                                $post['boxes'] = array_values($post['boxes']);
                            }
                        }
                    }
                }
                unset($post['post_type']);
            }

            return $list;
        }
        else
            return array();
    }

    /**
     * This function converts a timestamp in the internal database format
     *
     * Some dates in the DB are stored in the form aaaammgg.
     * Here is a quick conversion.
     * Hour informations are discarded.
     * @param integer $timestamp Timestamp
     * @return string aaaammgg
     */
    protected function _shortDateFormat($timestamp)
    {
        return date('Ymd', $timestamp);
    }

    /**
     * Mount a subquery in an AND IN (subquery) clause
     *
     * A clause requesting that the given $field is IN the subquery
     * result will be added to a $query.
     *
     * @param string $query External query
     * @param string $field Involved field
     * @param string $subquery Internal query
     * @return string The complex query
     */
    protected function _mountSubquery($query, $field, $subquery)
    {
        if ($subquery)
            return $query . ' AND ' . $field . ' IN (' . $subquery . ')';
        else
            return $query;
    }

    /**
     * Collect the database parameters and connect
     *
     * @param string $db_name Database name
     * @param string $user User name
     * @param string $pass Password
     * @param string $host Host
     * @return void
     */
    public function __construct($db_name, $user, $pass = '', $host = 'localhost')
    {
        $this->_db = new \PDO('mysql:dbname=' . $db_name . ';host=' . $host, $user, $pass);
    }

    /**
     * Languages
     *
     * List of available languages
     *
     * @return array List of available languages
     */
    public function langs()
    {
        $query = 'SELECT code FROM www_icl_locale_map';
        $statement = $this->_db->prepare($query);

        $langs = array();
        foreach ($this->_execute($statement) as $lang)
            $langs[] = $lang['code'];

        return $langs;
    }

    /**
     * Pages
     *
     * Static pages, mostly from the menus
     *
     * @param string $lang Language
     * @return array Page tree, as array
     */
    public function pages($lang)
    {
        $pages = array($lang => array());

        // Mappa
        $query = 'SELECT post_id as id,label FROM www_menu WHERE lang = :lang';
        $statement = $this->_db->prepare($query);
        $statement->bindValue('lang', (string) $lang);

        $result = $this->_execute($statement);
        foreach ($result as & $item)
            $item['pages'] = array();

        return array($lang => $this->_menuChildren($result)); // Recursive!
    }

    /**
     * Categories
     *
     * Return the categories tree, by language
     *
     * @param string $lang Language
     * @return array Categories' tree, as array
     */
    public function categories($lang)
    {
        // Categories names
        $query = 'SELECT www_terms.name as label, www_categories.label as family
                    FROM www_terms
                        RIGHT JOIN www_categories ON www_categories.term_id = www_terms.term_id
                    WHERE www_categories.lang = :lang';
        $statement = $this->_db->prepare($query);
        $statement->bindValue('lang', (string) $lang);

        $categories = array();
        foreach ($this->_execute($statement) as $group)
            $categories[$group['family']] = array('label' => $group['label'], 'pages' => array());

        // Categories
        $query = 'SELECT www_terms.term_id as id, www_terms.name as label, www_categories.label as category, www_docs_joindw.id_dw as :i_stat, www_term_taxonomy.description
                    FROM www_terms
                        LEFT JOIN www_term_taxonomy ON www_term_taxonomy.term_id = www_terms.term_id
                        LEFT JOIN www_docs_joindw ON www_docs_joindw.id_wp = www_terms.term_id
                        LEFT JOIN www_categories ON www_term_taxonomy.parent = www_categories.term_id
                    WHERE www_categories.lang = :lang
                    ORDER BY category';
        $statement = $this->_db->prepare($query);
        $statement->bindValue('i_stat', 'I.Stat');
        $statement->bindValue('lang', (string) $lang);

        foreach ($this->_execute($statement) as $category)
        {
            // Here is the correction to a very strange suffix
            $category['label'] = preg_replace('/\s*@\w{2}\s*$/', '', $category['label']);

            $categories[$category['category']]['pages'][] = array(
                                                                'id'            => $category['id'],
                                                                'label'            => $category['label'],
                                                                'description'    => $category['description'],
                                                                'I.Stat'        => $category['I.Stat'],
                                                                'pages'            => array(),
                                                                );
        }

        return array($lang => $categories);
    }

    /**
     * Tags
     *
     * List of tags, with weight
     *
     * @param string $lang Language
     * @param integer $offset Offset (starting from 0)
     * @param integer $limit Number of returned items ($limit <= 0 means 'no limit')
     * @return array
     */
    public function tags($lang, $offset = 0, $limit = 20)
    {
        $query = 'SELECT DISTINCT www_terms.name, www_term_taxonomy.count
                    FROM www_terms
                        LEFT JOIN www_term_taxonomy ON www_term_taxonomy.term_id = www_terms.term_id
                        LEFT JOIN www_icl_translations ON www_icl_translations.element_id = www_terms.term_id
                    WHERE www_term_taxonomy.taxonomy = :taxonomy
                        AND www_term_taxonomy.count > 0
                        AND www_terms.term_id NOT IN (SELECT term_id FROM www_blacklisted_tags)
                        AND www_icl_translations.language_code = :lang
                    ORDER BY count DESC';
        $bindings = array(
                        'taxonomy'    => array('value' => 'post_tag', 'type' => \PDO::PARAM_STR),
                        'lang'        => array('value' => $lang, 'type' => \PDO::PARAM_STR),
                        );
        if ($limit > 0)
        {
            $query .= '
                    LIMIT :offset, :limit';
            $bindings['offset'] = array('value' => (integer) $offset, 'type' => \PDO::PARAM_INT);
            $bindings['limit'] = array('value' => (integer) $limit, 'type' => \PDO::PARAM_INT);
        }

        $statement = $this->_db->prepare($query);
        foreach ($bindings as $name => $binding)
            $statement->bindValue($name, $binding['value'], $binding['type']);

        return array($lang => $this->_execute($statement));
    }

    /**
     * Post
     *
     * Return the full content of a single post
     *
     * @param integer $id Post id
     * @return array the complete ontology of the post, as complex array
     */
    public function post($id)
    {
        if ($posts = $this->_posts(array($id), true))
            return $posts[0];
    }

    /**
     * Filtered posts lists
     *
     * This function admit many input parameters, aimed to the list filtering.
     * Each parameter is combined with the others in a AND logic,
     * i.e. each element of the returned will match ALL criteria.
     * However, if a parameter is an array, a OR logic will be used,
     * meaning that listed posts will match at least one item of the array.
     * An empty array will be considered as 'anything'.
     * The posts order is the dateOfIssue, followed by 'menu_order' and
     * the last modified time.
     *
     * @param string $lang Language
     * @param array $themes Themes list, as ids list
     * @param array $regions Regions list, as ids list
     * @param array $types Types list, as ids list
     * @param array $tags Tags list, as strings list
     * @param integer $pubmin Issue date not before this value (timestamp)
     * @param integer $pubmax Issue date not after this value (timestamp)
     * @param integer $periodmin Data period not starting before this value (timestamp)
     * @param integer $periodmax Data period not ending after this value (timestamp)
     * @param integer $offset Offset (starting from 0)
     * @param integer $limit Number of returned items ($limit <= 0 means 'no limit')
     * @return array
     */
     public function lists(
                        $lang,
                        $types = array(),
                        $themes = array(),
                        $regions = array(),
                        $tags = array(),
                        $pubmin = 0,
                        $pubmax = 0, // Se zero, verrà inteso come ADESSO
                        $periodmin = 0,
                        $periodmax = 0, // Se 0, verrà inteso come ADESSO
                        $offset = 0,
                        $limit = 10
                        )
    {
        // Casting
        $lang = (string) $lang;
        $types = (array) $types;
        $themes = (array) $themes;
        $regions = (array) $regions;
        $tags = (array) $tags;
        $pubmin = (integer) $pubmin;
        $pubmax = (integer) $pubmax;
        $periodmin = (integer) $periodmin;
        $periodmax = (integer) $periodmax;
        $offset = abs((integer) $offset);
        $limit = (integer) $limit;

        /*
            A quick note about the query logic.
            Parameters are naturally divided in two groups:
            - params that refer to www_posts o a www_icl_translations
            - params that refer to www_postmeta o a www_term_relationships
            Parameters in the second group must be managed as subqueries.

            First group:
            $lang, $pubmin, $pubmax

            Second group:
            $types, $themes, $regions, $ags, $periodmin, $periodmax

            Firstly, a complex query will be executed to collect the IDs.
            Then the other informations will be collected in other queries.
        */

        // Start from the most internal query
        // Everything will be mounted later
        $subquery = '';
        $bindings = array();

        // Period min
        if ($periodmin)
        {
            $periodmin = $this->_shortDateFormat($periodmin);
            $query = 'SELECT post_id FROM www_postmeta WHERE meta_key = :fineperiodo AND meta_value >= :periodomin';
            $bindings['fineperiodo'] = array('value' => 'fineperiodo');
            $bindings['periodomin'] = array('value' => $periodomin, 'type' => \PDO::PARAM_INT);
            $subquery = $this->_mountSubquery($query, 'post_id', $subquery);
        }

        // Period max
        if ($periodmax)
        {
            $periodmax = $this->_shortDateFormat($periodmax);
            $query = 'SELECT post_id FROM www_postmeta WHERE meta_key = :inizioperiodo AND meta_value <= :periodomax';
            $bindings['inizioperiodo'] = array('value' => 'inizioperiodo');
            $bindings['periodomax'] = array('value' => $periodmax, 'type' => \PDO::PARAM_INT);
            $subquery = $this->_mountSubquery($query, 'post_id', $subquery);
        }

        // A function could be useful for the next operations,
        // but there are too many parameters!

        // Types
        if ($types)
        {
            // Binding preparation
            $count = 1;
            $names = array();
            foreach ($types as $type)
            {
                $name = 'type' . $count;
                $bindings[$name] = array('value' => (integer) $type, 'type' => \PDO::PARAM_INT);
                $names[] = $name;
                $count++;
            }
            $query = 'SELECT object_id
                        FROM www_term_relationships
                        WHERE term_taxonomy_id IN (:' . implode(',:', $names) . ')';
            $subquery = $this->_mountSubquery($query, 'post_id', $subquery);
        }

        // Themes
        if ($themes)
        {
            // Binding preparation
            $count = 1;
            $names = array();
            foreach ($themes as $theme)
            {
                $name = 'theme' . $count;
                $bindings[$name] = array('value' => (integer) $theme, 'type' => \PDO::PARAM_INT);
                $names[] = $name;
                $count++;
            }
            $query = 'SELECT object_id
                        FROM www_term_relationships
                        WHERE term_taxonomy_id IN (:' . implode(',:', $names) . ')';
            $subquery = $this->_mountSubquery($query, 'post_id', $subquery);
        }

        // Regions
        if ($regions)
        {
            // Binding preparation
            $count = 1;
            $names = array();
            foreach ($regions as $region)
            {
                $name = 'region' . $count;
                $bindings[$name] = array('value' => (integer) $region, 'type' => \PDO::PARAM_INT);
                $names[] = $name;
                $count++;
            }
            $query = 'SELECT object_id
                        FROM www_term_relationships
                        WHERE term_taxonomy_id IN (:' . implode(',:', $names) . ')';
            $subquery = $this->_mountSubquery($query, 'post_id', $subquery);
        }

        // Tag
        if ($tags)
        {
            // Binding preparation
            $count = 1;
            $names = array();
            foreach ($tags as $tag)
            {
                $name = 'tag' . $count;
                $bindings[$name] = array('value' => $tag);
                $names[] = $name;
                $count++;
            }
            $query = 'SELECT object_id
                        FROM www_term_relationships
                            LEFT JOIN www_terms ON www_terms.term_id = www_term_relationships.term_taxonomy_id
                            LEFT JOIN www_term_taxonomy ON www_term_taxonomy.term_taxonomy_id = www_term_relationships.term_taxonomy_id
                        WHERE www_term_taxonomy.taxonomy = :taxonomy
                            AND www_terms.name IN (:' . implode(',:', $names) . ')';
            $bindings['taxonomy'] = array('value' => 'post_tag');
            $subquery = $this->_mountSubquery($query, 'post_id', $subquery);
        }

        // Now I can mount...

        // Minimal query
        $query = 'SELECT SQL_CALC_FOUND_ROWS DISTINCT www_posts.ID
                    FROM www_posts
                        LEFT JOIN www_postmeta ON www_postmeta.post_id = www_posts.ID
                        LEFT JOIN www_icl_translations ON www_icl_translations.element_id = www_posts.ID
                    WHERE www_posts.post_status = :post_status AND www_posts.post_type = :post_type
                        AND www_icl_translations.language_code = :lang';
        $bindings['post_status'] = array('value' => 'publish');
        $bindings['post_type'] = array('value' => 'post');
        $bindings['lang'] = array('value' => $lang);

        if ($pubmin || $pubmax)
        {
            $query .= '
                        AND www_postmeta.meta_key = :data_pubblicazione';
            $bindings['data_pubblicazione'] = array('value' => 'data_pubblicazione');

            if ($pubmin)
            {
                // Conversion of $pubmin to the db format
                $pubmin = $this->_shortDateFormat($pubmin);
                $query .= '
                        AND www_postmeta.meta_value >= :pubmin';
                $bindings['pubmin'] = array('value' => $pubmin, 'type' => \PDO::PARAM_INT);
            }

            if ($pubmax)
            {
                // Conversion of $pubmax to the db format
                $pubmax = $this->_shortDateFormat($pubmax);
                $query .= '
                        AND www_postmeta.meta_value <= :pubmax';
                $bindings['pubmax'] = array('value' => $pubmax, 'type' => \PDO::PARAM_INT);
            }
        }

        // Mounting
        $query = $this->_mountSubquery($query, 'post_id', $subquery);

        // Not yet finished: order and limit
        $query .= ' ORDER BY menu_order ASC, post_date_gmt DESC';
        if ($limit >= 0)
        {
            $query .= ' LIMIT :offset, :limit';
            $bindings['offset'] = array('value' => $offset, 'type' => \PDO::PARAM_INT);
            $bindings['limit'] = array('value' => $limit, 'type' => \PDO::PARAM_INT);
        }

        // Preparation
        $statement = $this->_db->prepare($query);
        foreach ($bindings as $name => $param)
        {
            if (!isset($param['type']))
                $param['type'] = \PDO::PARAM_STR;
            $statement->bindValue($name, $param['value'], $param['type']);
        }

        // Execution
        $list = $this->_execute($statement);

        $result = array('list' => array(), 'count' => 0);
        foreach($list as $item)
            $result['list'][] = $item['ID'];

        // Count
        $query = 'SELECT FOUND_ROWS() as count';
        $statement = $this->_db->prepare($query);
        $count = $this->_execute($statement);

        $result['count'] = $count[0]['count'];

        // Now $result['list'] is a raw list of IDs
        // Populating...
        $result['list'] = $this->_posts($result['list'], false);

        return array($lang => $result);
    }
}
