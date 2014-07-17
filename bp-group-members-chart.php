<?php
/*
Plugin Name: BuddyPress group members chart
Plugin URI: https://github.com/stephandekker/bp-group-members-chart
Description: Adds the [bp-group-members-chart] shortcode to a BuddyPress instance that will display a group org-chart
Version: 0.1 BETA
Author: Stephan Dekker
Author URI: http://www.stephandekker.com
*/

/*
BuddyPress group members chart (Wordpress Plugin)
Copyright (C) 2014 Stephan Dekker
Contact me at http://www.stephandekkker.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

//Read more at http://hardcorewp.com/2012/using-classes-as-code-wrappers-for-wordpress-plugins/#de79ljkjJBXwtsAT.99
class BP_Group_Members_Chart {

   private $bp_group_members_chart_render = True;
   private $bp_group_members_chart_jscript = "";
   private $ignoreusers = "administrator";

   function __construct() {
      // Tell wordpress to register the shortcode
      add_shortcode("bp-group-members-chart", array( $this, "bp_group_members_chart_handler"));      

      // Add javascripts to HTML header
      add_action('wp_head', array( $this, 'addHeaderCode'), 1);
   }

   // Add the OrgChart javascripts
   function addHeaderCode() {
      echo '<script type="text/javascript" src="'. plugins_url( 'js/orgchart.js' , __FILE__ ) .'"></script>' . "\n";  
   }  

    function bp_group_members_chart_handler() {    
      // Build the org chart HTML and generate the rendering javascript in $bp_group_members_chart_jscript.
      $Orgchart_HTML = $this->bp_group_members_chart_function();

      // Add orgchart rendering scripts to the footer
      $this->bp_group_members_chart_render = True;
      add_action( 'wp_footer', array( $this, 'print_my_inline_script') );

      // send back text to replace shortcode in post
      return $Orgchart_HTML;
   }


    function getAdmins($group) {
       global $wpdb;
       $db_admins = $wpdb->get_results( "SELECT users.display_name FROM wp_bp_groups_members members, wp_users users WHERE members.user_id = users.id and members.group_id = '".$group->id."' and members.is_admin = '1';" );       
       
       $group->admins = array();
       foreach ($db_admins as $db_admin) {
           $adminname = $db_admin->display_name;

           if ($adminname === $this->ignoreusers) { continue; }           
           $adminname = esc_html($adminname);

           array_push($group->admins, $adminname);
       }
    }

    // Find the top level groups
    function getRoot($name, $groups)  {
      foreach ( $groups as $potentialRoot) {
         if ($potentialRoot->name === $name)
         {
           return $potentialRoot;
         }
      }
    }

    // Get the children for the given groups
    function getChildrenForGroup($group, $allGroups) {

      $children = array();
      foreach ( $allGroups as $potentialChild ) {
        if ($potentialChild->parent_id == $group->id)
        {
           array_push($children, $potentialChild);
        }   
      }
  
      return $children;
    }


    // Get the number of people in a group given the group ID
    function getMemberCountByGroupId($groupId, $members) {
      $count = 0;
      foreach ( $members as $member) {
         if ($member->group_id === $groupId)
         {
            $count += 1;
         }
      }
      return $count;
    }

    // Determine the number of people in a group and all its subgroups
    function getMemberCount($group, $allGroups, $members) {

      $children = $this->getChildrenForGroup($group, $allGroups);
      if (count($children) == 0)
      {
         // No Children, get the member count
         $group->members = $this->getMemberCountByGroupId($group->id, $members);
         
         // Also find out the admins for this group
         $this->getAdmins($group);
      }
      else
      {
         foreach ($children as $child)
         {
            $this->getMemberCount($child, $allGroups, $members);
            $group->members += $child->members;
         }
         $this->getAdmins($group);
      }
  
      return $allGroups;
    }
    
    function Add_Child_Nodes ($group, $allGroups) {

      $children = $this->getChildrenForGroup($group, $allGroups);
      if (count($children) == 0) { return; }
         
      foreach ($children as $child) {
          $content = $this->buildNodeContent($child);
      
          $this->bp_group_members_chart_jscript .= "       o.addNode( '".$child->id."', '".$group->id."', 'r', '".$content."');  ";               
          
          $this->Add_Child_Nodes ($child, $allGroups);
      }
    }
    
    function buildNodeContent($group) {
       
       $node_content = $group->name." (".$group->members.")";
       $title = $node_content;
       foreach ($group->admins as $admin) {
          $node_content .= "\nLead: ".$admin;       
       }
       $node_content = esc_js($node_content);
    
       return $node_content;
    }

    // Build the chart
    function bp_group_members_chart_function() {

      // Get data from the database
      global $wpdb;
      $groups = $wpdb->get_results( "SELECT * FROM wp_bp_groups;" );
      $members = $wpdb->get_results( "SELECT * FROM wp_bp_groups_members;" );
      $root = $this->getRoot("Global", $groups);        
      $this->getAdmins($root);

      // Build up results table
      $groups = $this->getMemberCount($root, $groups, $members);

      $this->bp_group_members_chart_jscript = "        var o = new orgChart();              ";
      $this->bp_group_members_chart_jscript .= "       o.setSize(200, 60);  ";
      $this->bp_group_members_chart_jscript .= "       o.setFont('Arial', 10);  ";
            
      $this->bp_group_members_chart_jscript .= "       o.addNode( '".$root->id."', '', '', '".$root->name." (".$root->members.")');  ";
      
      $this->Add_Child_Nodes($root, $groups);
      
      $this->bp_group_members_chart_jscript .= "       o.drawChart('canvas1');              ";

      // Render Output  
      $output = '   <canvas id="canvas1" width="800" height="2000"></canvas>';      
  
      //send back text to calling function
      return $output;
    }

    function print_my_inline_script() {
       if ($this->bp_group_members_chart_render) {
       ?>
       <script type="text/javascript">
           <?php  echo $this->bp_group_members_chart_jscript;  ?>
       </script>
       <?php
       }
    }

}
new BP_Group_Members_Chart();


?>

