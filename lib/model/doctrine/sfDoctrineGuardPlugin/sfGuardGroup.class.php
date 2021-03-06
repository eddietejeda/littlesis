<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
class sfGuardGroup extends PluginsfGuardGroup
{
  public function getInternalUrl($action=null, Array $params=null)
  {
    return sfGuardGroupTable::getInternalUrl($this, $action, $params);
  }

  
  public function getUserIds()
  {
    $db = Doctrine_Manager::connection();
    $sql = 'SELECT user_id FROM sf_guard_user_group ug WHERE ug.group_id = ?';
    $stmt = $db->execute($sql, array($this->id));

    return $stmt->fetchAll(PDO::FETCH_COLUMN);  
  }
  
  public function getListIds()
  {
    $db = Doctrine_Manager::connection();
    $sql = 'SELECT list_id FROM sf_guard_group_list gl WHERE gl.group_id = ?';
    $stmt = $db->execute($sql, array($this->id));

    return $stmt->fetchAll(PDO::FETCH_COLUMN);  
  }
  
  public function getEntityIds()
  {
    $entity_ids = array();
    
    if (count($list_ids = $this->getListIds()))
    {
      $list_ids = '(' . implode(',',$this->getListIds()) . ')';
      $db = Doctrine_Manager::connection();
      
      //GET ENTITIES
      $sql = 'SELECT entity_id FROM ls_list_entity WHERE list_id in ' . $list_ids;
      $stmt = $db->execute($sql, array('23'));
      $entity_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    return $entity_ids;
  }
  
  public function getTopAnalystsQuery($excluded_user_ids = null)
  {
    $q = LsDoctrineQuery::create()
        ->select('u.*, ug.score AS group_score')
        ->from('sfGuardUser u')
        ->leftJoin('u.sfGuardUserGroup ug')
        ->where('ug.group_id = ? and ug.score > ?', array($this->id,'0'))
        ->andWhereNotIn('u.id',$excluded_user_ids)
        ->orderBy('ug.score DESC');
    return $q;  
  }
  
  public function refreshScores($list_ids = null, $start_date = null)
  {
    if (!$start_date)
    {
      $start_date = $this->created_at;
    }
    if (!$list_ids)
    {
      $list_ids = '(' . implode(',',$this->getListIds()) . ')';
    }
    $user_ids = '(' . implode(',',$this->getUserIds()) . ')';
    
    $db = Doctrine_Manager::connection();
    
    //GET ENTITIES
    $sql = 'SELECT entity_id FROM ls_list_entity WHERE list_id in ' . $list_ids;
    $stmt = $db->execute($sql, array('23'));
    $entity_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $entity_ids = '(' . implode(",",$entity_ids) . ')';
    
    //GET ENTITIES' RELATIONSHIPS
    $sql = 'SELECT id FROM relationship WHERE entity1_id in ' . $entity_ids . ' OR entity2_id in ' . $entity_ids;
    $stmt = $db->execute($sql);
    $relationship_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $relationship_ids = '(' . implode(",",$relationship_ids) . ')';
    
    //GET IMAGES
    $sql = 'SELECT id FROM image WHERE entity_id in ' . $entity_ids ;
    $stmt = $db->execute($sql);
    $image_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $image_ids = '(' . implode(",",$image_ids) . ')';   
    
    //GET MODIFICATIONS
    $sql = 'SELECT m.user_id,count(m.id) AS mod_count FROM modification m WHERE m.created_at > ? and m.user_id in ' . $user_ids . ' and ((m.object_model = ? and m.object_id in ' . $entity_ids . ') OR (m.object_model = ? and m.object_id in ' . $relationship_ids . ') OR (m.object_model = ? and m.object_id in ' . $image_ids . ')) GROUP BY m.user_id ORDER BY mod_count DESC' ;
    $stmt = $db->execute($sql,array($start_date, 'Entity',
     'Relationship','Image'));
    $mods = $stmt->fetchAll();
    //var_dump($mods);
    $top_analysts = array();
    foreach($mods as $mod)
    {
      $user_group = LsDoctrineQuery::create()
          ->from('sfGuardUserGroup')
          ->where('group_id = ? and user_id = ?',array($this->id, $mod['user_id']))
          ->fetchOne();
      $user_group->score = $mod['mod_count'];
      $user_group->save();
    }
  }  
}