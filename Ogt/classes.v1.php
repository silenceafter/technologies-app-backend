<?php
class Ogt extends GUIGenerator
{
	public function RequireModuls()
	{
		parent::RequireModuls();
    /*
    <link rel='stylesheet' type='text/css' href='../npm/node_modules/bootstrap/dist/css/bootstrap.min.css'>    
     */
		echo "
      <link rel='stylesheet' type='text/css' href='../npm/node_modules/shepherd.js/dist/css/shepherd.css'>";
		echo "
      <script src='../npm/node_modules/shepherd.js/dist/js/shepherd.js'></script>
      <script src='../npm/node_modules/bootstrap/dist/js/bootstrap.js'></script>";
	}
	public function MainGeneration()
	{
		echo '';
	}
}
?>