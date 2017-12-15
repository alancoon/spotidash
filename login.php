<?php
	require 'vendor/autoload.php';
	require 'config/db_config.php';
	require 'config/spotify_config.php';
	require 'debug.php';
	$session = new SpotifyWebAPI\Session(
		CLIENT_ID,
		CLIENT_SECRET,
		REDIRECT_URI
	);
	$api = new SpotifyWebAPI\SpotifyWebAPI();
	$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
	if ($mysqli->connect_errno) {
		echo "MySQL Error: " . $mysqli->connect_errno;
	} else {
		$mysqli->set_charset('utf8');
		if (isset($_GET['code']) && !empty($_GET['code'])) {
			$session->requestAccessToken($_GET['code']);
			$api->setAccessToken($session->getAccessToken());
		} else {
			$options = [
				'scope' => [
					'user-top-read',
					'user-read-email',
				],
			];
			header('Location: ' . $session->getAuthorizeUrl($options));
			die();
		}
		// Short term.
		$options = [
			'limit' => 50,
			'time_range' => 'short_term',
		];
		$query = $api->getMyTop('tracks', $options);
		$topTracksShort = $query->items;
		// Medium term.
		$options['time_range'] = 'medium_term';
		$query = $api->getMyTop('tracks', $options);
		$topTracksMedium = $query->items;
		// Long term.
		$options['time_range'] = 'long_term';
		$query = $api->getMyTop('tracks', $options);
		$topTracksLong = $query->items;
		$topTracks = array_merge($topTracksShort, $topTracksMedium);
		$topTracks = array_merge($topTracksLong, $topTracks);
		$topTracks = array_map("unserialize", array_unique(array_map("serialize", $topTracks)));
		
		for ($i = 0; $i < sizeof($topTracks); $i = $i + 1) {
			$current = $topTracks[$i];
			// Album data:
			$album_id = $current->album->id;
			$album_name = $current->album->name;
			$album_img_url = $current->album->images[0]->url; // [2] is High-Res.
			$album_url = $current->album->external_urls->spotify;
			// Artist data:
			$artist_id = $current->artists[0]->id;
			$artist_name = $current->artists[0]->name;
			$artist_url = $current->artists[0]->external_urls->spotify;
			// Track data:
			$track_id = $current->id;
			$track_name = $current->name;
			$track_url = $current->external_urls->spotify;
			$track_preview_url = $current->preview_url;
			$sql = "SELECT 1
					FROM songs
					WHERE songs.spotify_id LIKE " .
					$track_id . ";";
			$results = $mysqli->query($sql);
			if (!$results) {
				// Not in database yet.  Push up to database.
				$artist_query = "SELECT 1
								 FROM artists
								 WHERE artists.spotify_id LIKE " . 
								 $artist_id . ";";
				$artist_results = $mysqli->query($artist_query);
				$album_query = "SELECT 1
								FROM albums
								WHERE albums.spotify_id LIKE " . 
								$album_id . ";";
				$album_results = $mysqli->query($album_query);
				// Check to see if the artist is already in the database.
				if (!$artist_results) {
					// Artist not in database.
					$insert_artist = "INSERT INTO artists
									  (spotify_id, name, url)
									  VALUES
									  ('" . $artist_id . "', '" . $artist_name . "', '" . $artist_url . "');";
					$insert_artist_results = $mysqli->query($insert_artist);
					if (!$insert_artist_results) {
						// echo "MySQL Error while inserting new artist: " . $mysqli->error;
					}
				} 
				// Check to see if the album is already in the database.
				if (!$album_results) {
					// Album not in database.
					$insert_album = "INSERT INTO albums
									 (spotify_id, name, image_url, url)
									 VALUES
									 ('" . $album_id . "', '" . $album_name . "', '" . $album_img_url . "', '" . $album_url . "');";
					$insert_album_results = $mysqli->query($insert_album);
					if (!$insert_album_results) {
						// echo "MySQL Error while inserting new album: " . $mysqli->error;
					}
				} 
				// Get the artist table ID.
				$artist_table_id_query = "SELECT id
										  FROM artists
										  WHERE artists.spotify_id LIKE " .
										  $artist_id . ";";
				$album_table_id_query = "SELECT id
										 FROM albums
										 WHERE albums.spotify_id LIKE " . 
										 $album_id . ";";
				$artist_table_id_results = $mysqli->query($artist_table_id_query);
				$album_table_id_results = $mysqli->query($album_table_id_query);  
				if (!$artist_table_id_results || !$album_table_id_results) {
					// echo "MySQL Error while fetching artist or album ID: " . $mysqli->error;
				} else {
					$sql = "INSERT INTO songs 
						(spotify_id, name, url, preview_url, artist, album, genre, hits) 
						VALUES 
						('" . $track_id . "', '" . $track_name . "', '" . $track_url . "', '" . $track_preview_url . "', " . $artist_table_id_results . ", " . $album_table_id_results . ", 0, 1);";
					$results = $mysqli->query($sql);
					if (!$results) {
						// echo "MySQL Error inserting track data: " . $mysqli->error;
					}
				}
			} else { 
				// Already exists in database, increment.
				$hits_query = "SELECT hits
							   FROM songs
							   WHERE songs.spotify_id LIKE " .
							   $track_id . ";";
				$hits_results = $mysqli->query($hits_query);
				if (!$hits_results) {
					// echo "MySQL Error fetching track hits: " . $mysqli->error;
				} else {
					$new_hits = $hits_results + 1;
					$update = "UPDATE songs
							   SET hits = " . $new_hits . "
							   WHERE songs.spotify_id LIKE " .
							   $track_id . ";";
					$update_results = $mysqli->query($update);
					if (!$update_results) {
						// echo "MySQL Error updating track hits: " . $mysqli->error;
					}
				}
			} 
		}
	}
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Spotidash | Your Spotify Dashboard</title>
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta/css/bootstrap.min.css">
	<style>
		@font-face {
			font-family: "Brandon Grotesque";
			src: url("Brandon Grotesque/Brandon_bld.otf");
			font-weight: bold;
		}
		@font-face {
			font-family: "Brandon Med It";
			src: url("Brandon Grotesque/Brandon_med_it.otf");
		}
		@font-face {
			font-family: "Brandon Thin It";
			src: url("Brandon Grotesque/Brandon_thin_it.otf");
		}
		#header {
			background-color: #67CEC5;
			color: #FFFFFF;
			line-height: 55px;
			height: 55px;
			vertical-align: middle;
			width: auto;
			position: -webkit-sticky;
			position: sticky;
			top: 0px;
		}
		#subheader {
			/*background-color: #67CEC5;*/
			/*background-color: #DD0000;*/
			/*background-color: #B8D8D8;*/
			visibility: none;
			color: #FFFFFF;
			line-height: 50px;
			height: 50px;
			/*vertical-align: middle;*/
			/*justify-content: center;*/
			/*text-align: center;*/
			position: -webkit-sticky;
			position: sticky;
			top: 55px;
			display: block;
			width: 100%;
		}
		.btn-group {
/*			width: 200px;
			margin: auto;
			position: absolute;
			float: center;*/
			width: 350px;
		    position: absolute;
   	 		left: 50%;           /* Start at 50% of browser window */
    		margin-left: -175px; /* Go half of width to the left, centering the element */
		}
		#header > span > h1 {
			margin-bottom: 0px;
			padding-left: 10px;
			padding-top: 10px;
			padding-bottom: 0px;
			float: left;
			font-family: "Brandon Med It", Helvetica;
		}
		#header > span > h3 {
			margin-bottom: 0px;
			padding-left: 10px;
			padding-top: 20px;
			padding-bottom: 10px;
			float: left;
			font-family: "Brandon Thin It", Helvetica;
		}
		#header > span > img {
			height: 50px;
			float: left;
			padding-left: 10px;
			padding-top: 7px;
		}
		body {
			background-color: #67CEC5;
			font-family: "Brandon Grotesque", "Helvetica", Sans-serif;
			padding: 0px;
			width: auto;
		}
		#body {
			margin: auto;
			width: auto;
		}
		#footer {
			line-height: 40px;
			height: 40px;
			background-color: #67CEC5;
			color: #FFFFFF;
			text-align: center;
		}
		#footer > p {
			margin-bottom: 0px;
		}
		#albums {
			background-color: #082222;
			/*background-color: #67CEC5;*/
			display: flex;
			display: -webkit-flex;
		}
		#artists {
			background-color: #082222;
			/*background-color: #67CEC5;*/
			display: none;
		}
		#genres {
			/*background-color: #082222;*/
			background-color: #FFFFFF;
			/*background-color: #67CEC5;*/
			display: none;
		}
		#about {
			background-color: #FFFFFF;
			display: none;
			padding-top: 20px;
			padding-bottom: 20px;
			/*width: 66%;*/
			/*margin: auto;*/
		}
		#about-text {
			background-color: #FFFFFF;
			width: 66%;
			margin: auto;
		}
		#about-text > p {
			font-family: Helvetica, Arial, Sans-serif;
			font-size: 16;
		}
		.btn-primary {
			background-color: #67CEC5;
			border: none;
			/*padding-left: 5px;*/
			/*padding-right: 5px;*/
			/*border-color: #67CEC5;*/
		}
		.btn-primary:hover {
			border: 1px;
			border:solid;
			background-color: #B8D8D8;
			border-color: #FF0000;
		}
		.btn-primary {
			border: none;
			width: 25%;
		}
		.btn-primary:hover {
			border:2px;
		}
		.btn-primary:disabled {
			opacity: 1;
			background-color: #B8D8D8;
			/*border-color: #B8D8D8;*/
		}
		.btn-primary:active {
			/*border:none;*/
			border: 3px;
			border: solid;
			/*border-color: #BDF4F4;*/
			border-color: #B8D8D8;
  			/*box-shadow: 0 3px 0 #00823F;*/
  			top: 1px;
		}
		.sticky {
			position:sticky;
			z-index: 9999;
		}
		.tab {
			margin: 0 auto;
			text-align: center;
			display: flex;
			display: -webkit-flex;
			flex-wrap: wrap;
			justify-content: center; /* align horizontal */
		    width: 100%; /*can be in percentage also.*/
    		height: auto;
		    position: relative;
		}
		
		.card {
			flex: 1;
			border: none;
			min-width: 192px;
			max-width: 640px;
			overflow: hidden;
/*			-webkit-user-select: none;
			-moz-user-select: none;
			-ms-user-select: none;*/
			user-select: none;
			padding: 0px;
		    background-color: #082222;
			/*background-color: #67CEC5;*/
		}
		#artists > .card {
			max-width: 600px;
		}
		#genres > .tags {
			padding-top: 20px;
			padding-bottom: 20px;
			width: 100%;
			text-overflow: wrap;
		}
		#genres > .tags > .tag {
			padding: 10px;
		}
		.card:hover > .hover {
			opacity: 0.60;
		}
		.card-img {
			min-height: 192px;
			max-height: 640px;
			overflow: hidden;
			position: relative;
			top: 0;
			bottom: 0;
			margin: auto;
		}
		.hover {
			background-color: #050505;
			text-align: center;
			vertical-align: middle;
			position: absolute;
			opacity: 0;
			height: 100%;
			width: 100%;
			margin: 0px;
			padding: 0px;
		}
		.hover-text {
			position: absolute;
			margin-top: 33%;
			margin-left: auto;
			margin-right: auto;
			width: 100%;
			font-size: 16pt;
		}
		.hover-link {
			color: #FFFFFF;
		}
		.hover-link:hover {
			color: #B8D8D8;
		}
		.clearfloat {
			clear: both;
		}
		/*@media (max-width: 1199px) {*/
		@media (max-width: 768px) {
			#header > span > h1 {
				padding-bottom: 5px;
			}
			#artists > .card > .hover {
				max-width: 640px;
				float: center;
				margin: 0px;
			}
			#artists > .card {
				width: 100%;
				float: center;
			}
			#artists > .card > .card-img {
				max-width: 640px;
				margin: 0px;
			}
			.hover-text {
				max-width: 640px;
			}
			.card {
				width: 100%;
				float: center;
			}
			.card-img {
				max-width: 640px;
			}
		}
		@media (max-width: 600px) {
			.card-img {
				max-width: 600px;
			}
			#artists > .card > .hover {
				max-width: 600px;
				margin: 0px;
				float: center;
			}
		}
		@media (max-width: 425px) {
			#header {
				max-width: 425px;
			}
			#header > span > h1 {
				font-size: 28pt;
			}
			#header > span > h3 {
				display: none;
			}
			#footer {
				line-height: 60px;
				height: 60px;
			}
			#footer > p {
				font-size: 10pt;
			}
			#artists > .card > .hover {
				max-width: 425px;
				margin: 0px;
				float: center;
			}
			
			#artists > .card {
				width: 100%;
				float: center;
			}
			#artists > .card > .card-img {
				max-width: 425px;
			}
			.hover-text {
				max-width: 425px;
			}
			.card {
				width:100%;
				float: center;
			}
			.card-img {
				max-width:425px;
			}
		}
		@media (max-width: 375px) {
			#artists > .card > .hover {
				width: 100%;
				float: center;
			}
			#artists > .card > .card-img {
				max-width: 375px;
			}
			.hover-text {
				max-width: 290px;
			}
			.card {
				width:100%;
				float: center;
			}
			.card-img {
				max-width: 375px;
			}
		}
		@media (max-width: 320px) {
			.card-img {
				max-width: 320px;
			}
			.hover-text {
				max-width: 200px;
			}
		}
	</style>
</head>
<body>
<div id="header" class="sticky">
	<span><h1>SPOTIDASH</h1><h3>powered by</h3><img src="img/49097.png" alt="Spotify Logo"><div class="clearfloat"></div>
	</span>
</div> <!-- #header -->
<div id="subheader" class="sticky">
	<div id="button-group" class="btn-group">
    	<button id="button-album" type="button" class="btn btn-primary" disabled>ALBUM</button>
    	<button id="button-artist" type="button" class="btn btn-primary">ARTIST</button>
    	<button id="button-genre" type="button" class="btn btn-primary">GENRE</button>
    	<button id="button-about" type="button" class="btn btn-primary">ABOUT</button>
  	</div>
</div>
<div id="body">
	<div id="albums" class="tab">
	<?php 
		for ($i = 0; $i < sizeof($topTracks); $i = $i + 1) {
			$current = $topTracks[$i];
			// Album data:
			$album_id = $current->album->id;
			$album_name = $current->album->name;
			$album_img_url = $current->album->images[0]->url;
			$album_url = $current->album->external_urls->spotify;
			// Artist data:
			$artist_id = $current->artists[0]->id;
			$artist_name = $current->artists[0]->name;
			$artist_url = $current->artists[0]->external_urls->spotify;
			// Track data:
			$track_id = $current->id;
			$track_name = $current->name;
			$track_url = $current->external_urls->spotify;
			$track_preview_url = $current->preview_url;
			// Add to the albums array:
			if ($added_albums[$album_id] == 1) {
				// Do nothing, we already have this album added.
			} else if (isset($album_img_url) && !empty($album_img_url)) {
				$added_albums[$album_id] = 1;
	?>      
				<div class="card"> 
					<img class="card-img" src="<?php echo $album_img_url; ?>" alt="<?php echo $album_name; ?>">
					<div class="hover">
						<div class="hover-text">
							<a class="hover-link" href="<?php echo $album_url; ?>">
								<?php echo "<b>$album_name</b><br>"; ?>
							</a>
							<a class="hover-link" href="<?php echo $artist_url; ?>">
								<?php echo "<i>$artist_name</i>"; ?>
							</a>
							<!--a href="<?php echo $track_preview_url; ?>">
								<?php echo "$track_preview_url"; ?>
							</a-->
						</div> <!-- .hover-text -->
					</div> <!-- .hover -->
				</div> <!-- .card -->
	<?php
			}
		}
	?>
	</div> <!-- #albums -->
	<div id="artists" class="tab">
	<?php 
		// Short term.
		$options = [
			'limit' => 50,
			'time_range' => 'short_term',
		];
		$query = $api->getMyTop('artists', $options);
		$topArtistsShort = $query->items;
		// Medium term.
		$options['time_range'] = 'medium_term';
		$query = $api->getMyTop('artists', $options);
		$topArtistsMedium = $query->items;
		// Long term.
		$options['time_range'] = 'long_term';
		$query = $api->getMyTop('artists', $options);
		$topArtistsLong = $query->items;
		$topArtists = array_merge($topArtistsShort, $topArtistsMedium);
		$topArtists = array_merge($topArtistsLong, $topArtists);
		$topArtists = array_map("unserialize", array_unique(array_map("serialize", $topArtists)));
		for ($i = 0; $i < sizeof($topArtists); $i = $i + 1) {
			$current = $topArtists[$i];
			$artist_tab_name = $current->name;
			$artist_tab_id = $current->id;
			$artist_tab_url = $current->external_urls->spotify;
			$artist_tab_genres = $current->genres;
			$artist_tab_image_url = $current->images[0]->url;
			$artist_tab_image_height = $current->images[0]->height;
			$artist_tab_image_width = $current->images[0]->width;
			if ($added_artists[$artist_tab_id]) {
				// Do nothing.
			} else if (isset($artist_tab_image_url) && !empty($artist_tab_image_url)) {
				$added_artists[$artist_tab_id] = 1;
				// Record all genres.
				for ($j = 0; $j < sizeof($artist_tab_genres); $j = $j + 1) {
					if ($genre_tally[$artist_tab_genres[$j]]) {
						$genre_tally[$artist_tab_genres[$j]] = $genre_tally[$artist_tab_genres[$j]] + 1;
					} else {
						$genre_tally[$artist_tab_genres[$j]] = 1;
					}
				}
	?>
				<div class="card">
					<img class="card-img" src="<?php echo $artist_tab_image_url; ?>" alt="<?php echo $artist_tab_name; ?>">
					<div class="hover">
						<div class="hover-text">
							<a class="hover-link" href="<?php echo $artist_tab_url; ?>">
								<?php echo "<i>$artist_tab_name</i>"; ?>
							</a>
						</div> <!-- .hover-text -->
					</div> <!-- .hover -->
				</div> <!-- .card -->
	<?php
			}
		}
	?>
	</div> <!-- #artists -->
	<div id="genres" class="tab">
	<?php 
		arsort($genre_tally);
		$taggly = new Watson\Taggly\Taggly;
		$tags = array();
		$l = 0;
		foreach ($genre_tally as $genre => $count) {
			if ($count > 3) {
				$count = $count * 5;
			}
			$tag = array('tag' => $genre, 'count' => $count);
			$tag = new Watson\Taggly\Tag($tag);
			$tags[$l] = $tag;
			$l = $l + 1;
		}
		$taggly->setTags($tags);
		$taggly->setMinimumFontSize(12);
		$taggly->setMaximumFontSize(96);
		echo $taggly->cloud();
	?>
	</div>
	<div id="about" class="tab">
		<div id="about-text">
			<h2>WHAT IS SPOTIDASH?</h2>
			<p>
				Spotidash is a tool you can use to visually analyze your favorite music on Spotify.
			</p>
			<p>
				Simply link your Spotify account, then browse your most played albums, artists, and genres.
			</p>
			<h4>
				ALBUMS
			</h4>
			<p>
				View your most played albums.  Examines both short term and long term Spotify data.  As you scroll downwards, the results should be more recent.  Hover over an album to see Spotify links to the album and artist.  You may want to use Google Chrome for this.
			</p>
			<h4>
				ARTISTS
			</h4>
			<p>
				View your most listened to artists.  Draws data from your short term and long term listening habits.  As you scroll downwards, the results should be more recent.  Hover over an artist's photo to see a Spotify link to their page.  You may want to use Google Chrome for this.
			</p>
			<h4>
				GENRES
			</h4>
			<p>
				View a word cloud of your favorite genres.  Genres you listen to more appear larger.  The genre tags are collected from your most listened to artists, you may be surprised at what ends up on there!
			</p>
			<h4>
				I USED
			</h4>
			<p>
				<a href="https://github.com/dwightwatson/taggly/">Taggly</a> by dwightwatson.
				<a href="https://github.com/jwilsson/spotify-web-api-php/">Spotify Web API PHP</a> by jwilsson.
			</p>
		</div>
	</div>
	<div class="clearfloat"></div>
</div> <!-- #body -->
<div class="clearfloat"></div>
<div id="footer"> 
<p>Created by <a href="https://github.com/alancoon/">Alan Coon</a>, University of Southern California.<p>
</div> <!-- #footer -->
</body>
<script type="text/javascript">
	var buttonAlbum = document.querySelector("#button-album");
	var buttonArtist = document.querySelector("#button-artist")
	var buttonGenre = document.querySelector("#button-genre");
	var buttonWhatIs = document.querySelector("#button-about");
	var tabAlbum = document.querySelector("#albums");
	var tabArtist = document.querySelector("#artists");
	var tabGenre = document.querySelector("#genres");
	var tabWhatIs = document.querySelector("#about");
	function hide(dom) {
        dom.style.display = "none";
	}
	function setFlex(dom) {
		dom.style.display = "flex";
		// if (navigator.userAgent.search("Safari") & gt; = 0 & amp; & amp; navigator.userAgent.search("Chrome") & lt; 0) {
		// 	// This browser is Safari.
		// 	dom.style.display = "-webkit-flex";
  //   	} else {
  //   		// This browser is not Safari.
  //   		dom.style.display = "flex"
  //   	}
	}
	function setBlock(dom) {
		dom.style.display = "block";
	}
	buttonAlbum.onclick = function() {
		this.disabled = true;
		buttonArtist.disabled = false;
		buttonGenre.disabled = false;
		buttonWhatIs.disabled = false;
		setFlex(tabAlbum);
		hide(tabArtist);
		hide(tabGenre);
		hide(tabWhatIs);
	}
	buttonArtist.onclick = function() {
		this.disabled = true;
		buttonAlbum.disabled = false;
		buttonGenre.disabled = false;
		buttonWhatIs.disabled = false;
		hide(tabAlbum);
		setFlex(tabArtist);
		hide(tabGenre);
		hide(tabWhatIs);
	}
	buttonGenre.onclick = function() {
		this.disabled = true;
		buttonAlbum.disabled = false;
		buttonArtist.disabled = false;
		buttonWhatIs.disabled = false;
		hide(tabAlbum);
		hide(tabArtist);
		setBlock(tabGenre);
		hide(tabWhatIs);
	}
	buttonWhatIs.onclick = function() {
		this.disabled = true;
		buttonAlbum.disabled = false;
		buttonArtist.disabled = false;
		buttonGenre.disabled = false;
		hide(tabAlbum);
		hide(tabArtist);
		hide(tabGenre);
		setBlock(tabWhatIs);
	}
</script>
</html>