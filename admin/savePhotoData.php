<?php
	include("SimpleImage_class.php");
	include("../_sharedIncludes/dbconnect.php");
	include("../_sharedIncludes/globals.php");
	
	$client_form_data = json_decode(file_get_contents('php://input'), true);
	$PhotoData = "";
	$photoInstanceID = "";
	if ($client_form_data['mode'] == "publishBook")
	{
		// Page photo population **********************************
		$pix_str = "SELECT BookPagePhotoID, BookPhotoURL, BookPageID, BookPagePhotoInstanceNum,
					BookPhotoWidth, BookPhotoHeight, BookPagePhotoWidth, BookPagePhotoHeight,
					BookPagePhotoStretchToFill
					FROM BookPhotos BP
					INNER JOIN BookPagePhotos BPP
						ON BP.BookPhotoID = BPP.BookPhotoID
					WHERE BookLoginUsername = '" . $_SESSION['BookLoginUsername'] . "'
						AND BookID = " . $client_form_data['bookID'] . "
						AND BookPagePhotoIpadOrientation != 'outside';";
		$images_sql = $mysqli->query($pix_str);
		while ($row = $images_sql->fetch_assoc())
		{
			$image = new SimpleImage();
			$image->load($g_image_directory_path_user . $row['BookPhotoURL']);
			if ($row['BookPagePhotoStretchToFill'] == 1)
				$image->resize($row['BookPagePhotoWidth'], $row['BookPagePhotoHeight']);
			else
			{
				$image_orig_ratio = $row['BookPhotoWidth'] / $row['BookPhotoHeight'];
				$image_page_ratio = $row['BookPagePhotoWidth'] / $row['BookPagePhotoHeight'];
				if ($image_page_ratio > $image_orig_ratio)
					$image->smart_crop($row['BookPagePhotoWidth'], $row['BookPagePhotoHeight']);
				else
				{
					$image->fit_to_width($row['BookPagePhotoWidth']);
					$newHeight = ($row['BookPagePhotoWidth'] * $row['BookPhotoHeight']) / $row['BookPhotoWidth'];
					$Ycoord = $row['BookPagePhotoYCoord'] + (($row['BookPagePhotoHeight'] - $newHeight) / 2);
					$mysqli->query("UPDATE BookPagePhotos
									SET BookPagePhotoYCoord = $Ycoord,
									BookPagePhotoHeight = $newHeight
									WHERE BookPagePhotoID = " . $row['BookPagePhotoID'] . ";");
				}
			}
			$new_filename = bookImageName($row['BookPageID'], $row['BookPagePhotoInstanceNum'], $row['BookPhotoURL']);
			$image->save($g_image_directory_path_user . $new_filename);
		}
		$images_sql->free();
		echo "Published!";
		exit(0);
	}
	else if ($client_form_data['mode'] == "delete")
	{
		if (!empty($client_form_data['instanceID']))
		{
			$db_str = "DELETE FROM BookPagePhotos
						WHERE BookPhotoID = " . $client_form_data['ID'] . "
						AND BookPagePhotoInstanceNum = " . $client_form_data['instanceID'] . ";";
			$mysqli->query($db_str);
			echo "Deleted:PhotoInstance," . $client_form_data['ID'] . "," . $client_form_data['instanceID'] . "," . $client_form_data['pageID'];
			exit(0);
		}
		else
		{
			$are_there_photo_instances_str = "SELECT BookPagePhotoID
											FROM BookPagePhotos
											WHERE BookPhotoID = " . $client_form_data['ID'] . ";";
			$stmt = $mysqli->prepare($are_there_photo_instances_str);
			$stmt->execute();
			$stmt->store_result();
			$there_are_photo_instances = ($stmt->num_rows > 0);
			$stmt->close();
			if ($there_are_photo_instances)
			{
				echo "Error:You cannot delete a photo when there are other copies that exist";
				exit(0);
			}
			else
			{
				$db_str = "SELECT BookPhotoURL
							FROM BookPhotos
							WHERE BookPhotoID = " . $client_form_data['ID'] . ";";
						
				$file_sql = $mysqli->query($db_str);
				$row = $file_sql->fetch_assoc();
				$filename = $row['BookPhotoURL'];
				$file_sql->free();
				$fullImagePath = $GLOBALS["g_image_directory_path"] . $_SESSION["BookLoginUsername"];
				$fullsize_filename = $fullImagePath . "/" . $filename;
				$med_filename = str_replace(".", $GLOBALS["g_suffix_medium_image"] . ".", $fullsize_filename);
				$small_filename = str_replace(".", $GLOBALS["g_suffix_small_image"] . ".", $fullsize_filename);
				if (is_file($fullsize_filename))
					unlink($fullsize_filename);
				if (is_file($med_filename))
					unlink($med_filename);
				if (is_file($small_filename))
					unlink($small_filename);
				$db_str = "DELETE
							FROM BookPhotos
							WHERE BookPhotoID = " . $client_form_data['ID'] . ";";
				$mysqli->query($db_str);
				echo "Deleted:Photo," . $client_form_data['ID'];
				exit(0);
			}
		}
	}
	else if ($client_form_data['mode'] == "add")
	{
		/****************************************
			We now pass stack order from client
		*****************************************

		// Get stack order number (aka z-index) for photo ********************************
		$stackOrder = 0;	
		$stack_order_str = "SELECT IFNULL(MAX(BookPagePhotoStackOrder), 0) as MaxStackOrder
					FROM BookPagePhotos
					WHERE BookPageID = " . $client_form_data['pageID'] . "
						AND BookPagePhotoIpadOrientation = '" . $client_form_data['orientation'] . "';";
		$stack_order_sql = $mysqli->query($stack_order_str);
		if (!$stack_order_sql)
			echo "no results";
		else
		{
			$row = $stack_order_sql->fetch_assoc();
			$stackOrder = $row['MaxStackOrder'] + 1;
		}
		$stack_order_sql->free();
		*/
		$photoInstanceID = 0;
	
		// Get instance number for photo ********************************
		$next_instance_str = "SELECT IFNULL(MAX(BookPagePhotoInstanceNum), 0) as MaxInstanceNum
					FROM BookPagePhotos
					WHERE BookPhotoID = " . $client_form_data['ID'] . ";";
		$next_instance_sql = $mysqli->query($next_instance_str);
		if (!$next_instance_sql)
			echo "no results";
		else
		{
			$row = $next_instance_sql->fetch_assoc();
			$photoInstanceID = $row['MaxInstanceNum'] + 1;
		}
		$next_instance_sql->free();
	
		// Insert photo instance into DB ********************************
		$db_str = "INSERT INTO BookPagePhotos (BookPageID, BookPhotoID, BookPagePhotoInstanceNum,
			BookPagePhotoIpadOrientation, BookPagePhotoXCoord, BookPagePhotoYCoord,
			BookPagePhotoWidth, BookPagePhotoHeight, BookPagePhotoStretchToFill, BookPagePhotoStackOrder)
			VALUES (" . $client_form_data['pageID'] . ", " . $client_form_data['ID'] . ", " . $photoInstanceID . ", 
			'" . $client_form_data['orientation'] . "', " . $client_form_data['Xcoord'] . ", " . $client_form_data['Ycoord'] . ", "
				. $client_form_data['width'] . ", " . $client_form_data['height'] . ", "
				. $client_form_data['stretchToFill'] . ", " . $client_form_data['stackOrder'] . ");";
	}
	else if ($client_form_data['mode'] == "update")
	{
		/*
		$query  = explode('&', $_SERVER['QUERY_STRING']);
		$params = array();

		foreach( $query as $param )
		{
			list($name, $value) = explode('=', $param);
			$params[urldecode($name)][] = urldecode($value);
		}
		*/
		$photoInstanceID = $client_form_data['instanceID'];
		// Update photos in DB ********************************
		$db_str = "UPDATE BookPagePhotos
					SET BookPagePhotoInstanceNum = " . $photoInstanceID;
					if (isset($client_form_data['orientation']))
						$db_str .= ", BookPagePhotoIpadOrientation = '" . $client_form_data['orientation'] . "'";
					if (isset($client_form_data['Xcoord']))
						$db_str .= ", BookPagePhotoXCoord = " . $client_form_data['Xcoord'];
					if (isset($client_form_data['Ycoord']))
						$db_str .= ", BookPagePhotoYCoord = " . $client_form_data['Ycoord'];
					if (isset($client_form_data['width']))
						$db_str .= ", BookPagePhotoWidth = " . $client_form_data['width'];
					if (isset($client_form_data['height']))
						$db_str .= ", BookPagePhotoHeight = " . $client_form_data['height'];
					if (isset($client_form_data['stretchToFill']))
						$db_str .= ", BookPagePhotoStretchToFill = " . $client_form_data['stretchToFill'];
					$db_str .= " WHERE BookPhotoID = " . $client_form_data['ID'] . "
					AND BookPagePhotoInstanceNum = " . $photoInstanceID . ";";
	}
	if (!empty($db_str))
		$mysqli->query($db_str);
	$PhotoData = "PhotoInstance:mode=" . $client_form_data['mode'] . "&loggingIn=false" . $item_delimiter . "pageID=" . $client_form_data['pageID']
		. "&ID=" . $client_form_data['ID'] . "&instanceID=" . $photoInstanceID
		. "&orientation=" . $client_form_data['orientation'] . "&Xcoord=" . $client_form_data['Xcoord'] . "&Ycoord=" . $client_form_data['Ycoord']
		. "&width=" . $client_form_data['width'] . "&height=" . $client_form_data['height'] . "&stretchToFill=" . $client_form_data['stretchToFill'];
	echo $PhotoData;
?>