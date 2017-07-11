<?php
  /*
   * This proxy is located between the JavaScript widget inserted into a content partner's website and
   * Mr. DLib's API.
   * 
   * Its purpose is to route the JavaScript widget's request to the correct API calls.
   *
   * The proxy has to be addressed using this format:
   * [host]/proxy?id=[MDL's id_original of the publication]&title=[publication's title]
   */

  // prevent CORS conflicts
  header('Access-Control-Allow-Origin: *');

  /* --- (1) LOAD CONFIGURATION --- */

  /**
   * Gets a parameter from the URL using $_GET. Checks if it is set.
   * 
   * @param string    $parameterName    name of parameter to get
   *
   * @return string   parameter
   */
  function getUrlParameter($parameterName) {
    $parameter = null;

    if (isset($_GET[$parameterName])) {
      $parameter = $_GET[$parameterName];
    } else {
      // this can be the case for optional parameters
      $parameter = null;
    }

    return $parameter;
  }

  // retrieve parameters handed to proxy
  $id = getUrlParameter('id');
  $title = getUrlParameter('title');
  $user = getUrlParameter('user');

  // handle advanced search - include adequate style sheet

  /**
   * Maps a user id to a style sheet, thus conducts the splitting of the users into different test groups.
   * This is done by checking if the timestamp of the user is dividable by two.
   * 
   * @param string    $userId    user id to map to a style sheet
   *
   * @return string   file name of the mapped style sheet
   */
  function mapUserIdToStyleSheet($userId) {
    // baseline
    $styleSheet = 'mediatum-1.css';

    if (($userId % 2) == 0) {
      // test case
      $styleSheet = 'mediatum-2.css';
    }

    return $styleSheet;
  }

  $styleSheet = 'mediatum-1.css';
  // check if cookies are enabled
  
  if ($user != null) {
    // map user id to style
    $styleSheet = mapUserIdToStyleSheet($user);
  }
?>
<link rel="stylesheet" href="<?=$styleSheet?>">
<?php

  // retrieve configuration of proxy
  $config = json_decode(file_get_contents('config.json'));
  $mr_dlib_api_version = $config->mrdlib;
  $partner_environment = $config->partner;
  $ui_version = $config->ui;
  // map configuration of API to API address
  switch ($mr_dlib_api_version) {
    case 'beta':
      $api = 'api-beta';
      break;
    case 'dev':
      $api = 'api-dev';
      break;
    case 'prod':
      $api = 'api';
      break;
    default:
      exit('Error: API version could not be correctly read from config file.');
  }



  /* --- (2) RETRIEVE RECOMMENDATIONS FROM MDL's API ---
   * First, recommendations for the document with the given ID are requested. If this fails, as a
   * fallback, recommendations for the given title are requested. This second approach always works.
   * Thus the retrieval of the recommendations always works.
   */

  /**
   * Retrieves the XML that is available under a given URL.
   * 
   * @param string    $url    URL under which the XML to retrieve is available
   *
   * @return string   XML
   */
  function retrieveXmlFromUrl($url) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_HTTPHEADER => array("cache-control: no-cache"),
      CURLOPT_SSL_VERIFYPEER => false,
    ));

    $response = curl_exec($curl);
    // detect error, print out error message, if an error occured
    if ($response === false) {
      exit('Error: curl ' + $url + ' failed: ' + curl_error($curl));
    }

    curl_close($curl);

    return simplexml_load_string($response);
  }

  /**
   * Checks if the given XML is valid and thus can be used for generating the HTML snippet
   * showing recommendations.
   * 
   * @param string    $xml    XML to check
   *
   * @return boolean  true if valid, false otherwise
   */
  function isXmlValid($xml) {
    if ($xml === false OR count($xml->related_articles->related_article) === 0) {
      return false;
    }

    // else
    return true;
  }

  // construct URL to retrieve recommendations using given id
  $retrieval_url_using_id = "https://".$api.".mr-dlib.org/v1/documents/".$id."/related_documents?app_id=mediatum";
  $xml = retrieveXmlFromUrl($retrieval_url_using_id);

  // check if recommendations are retrieved using the given ID, if that fails, retrieve
  // recommendations using the given title
  if (!isXmlValid($xml)) {
    $retrieval_url_using_title = "https://".$api.".mr-dlib.org/v1/documents/".$title."/related_documents?app_id=mediatum";
    $xml = retrieveXmlFromUrl($retrieval_url_using_title);

    if (!isXmlValid($xml)) {
      exit('Error: No recommendations could be retrieved.');
    }
  }

  // extract recommendations and number of recommendations from XML
  $recommendations = $xml->related_articles->related_article;
  $numRecommendations = count($recommendations);



  /* --- (3) GENERATE HTML SNIPPET FOR RECOMMENDATIONS ---
   * Iterate over recommendations and embed the relevant data.
   */
?>
<div id="mrdlib_header">&Auml;hnliche Publikationen</div>
<div id="mrdlib_body">
<ul>
<?php
  /**
   * Normalizes a given string.
   * 
   * @param string    $stringToNormalize    string to normalize
   *
   * @return string   normalized string
   */
  function normalize_string($stringToNormalize) {
    $result = trim($stringToNormalize);

    $result = str_replace("<![CDATA[", "", $result);
    $result = str_replace("]]>", "", $result);
    $result = filter_var($result, FILTER_SANITIZE_SPECIAL_CHARS);

    return $result;
  }

  /**
   * Normalizes a given author name. The author name needs to have the format "[first name] [last name]".
   * 
   * @param string    $authorNameToNormalize    author name to normalize
   *
   * @return string   normalized author name
   */
  function normalize_author_name($authorNameToNormalize) {
    $result = normalize_string($authorNameToNormalize);

    // check if first name of author is abbreviated, if so, add a point '.' to it
    $firstName = explode(" ", $result)[0];
    
    if (strlen($firstName) === 1) {
      $result = str_replace($firstName, $firstName.'.', $result);
    }

    return $result;
  }

  // iterate over recommendations
  for($i = 0; $i < $numRecommendations; $i++) {
    // prepare authors
    // crop more than two authors and replace them with "et al."
    $authorNames = (string) $recommendations[$i]->authors;
    $authorNamesArray = explode(",", $authorNames);
    $author = normalize_author_name($authorNamesArray[0]);
    if (count($authorNamesArray) > 1) {
      $author = $author.', '.normalize_author_name($authorNamesArray[1]);
    }
    if (count($authorNamesArray) > 2) {
      $author = $author.' et al.';
    }

    // prepare title
    $title = normalize_string($recommendations[$i]->title);

    // prepare abstract
    $abstract = normalize_string($recommendations[$i]->abstract);

    // prepare published in
    $publishedIn = normalize_string($recommendations[$i]->published_in);
?>
  <li>
<?php
  // handle enabled advanced recommendations - generate onclick and onmouseover handler if needed
  $eventHandler = '';
  if ($user != null) {
    $eventHandler = ' onclick="log_click(\''.$title.'\');"';
  }

  // save recommendation generation into string to avoid redundancy
  $divRecommendationAuthor = '<div id="mrdlib_recommendation_author">'.$author.'</div>';
  $divRecommendationTitle = '<div id="mrdlib_recommendation_title">'.$title.'</div>';
  $divRecommendationPublishedIn = '<div id="mrdlib_recommendation_publishedIn">'.$publishedIn.'</div>';

  $recommendationDivs = $divRecommendationAuthor.$divRecommendationTitle.$divRecommendationPublishedIn;
?>
    <!-- two blocks of recommendation meta information are introduced, the difference is the link target -->
    <!-- this is done to allow controlling in which target a recommendation is opened via the CSS file -->
    <a id="mrdlib_link_same_tab" href="<?=$recommendations[$i]->click_url;?>" <?=$eventHandler?>>
      <?=$recommendationDivs?>
    </a>
    <a id="mrdlib_link_new_tab" href="<?=$recommendations[$i]->click_url;?>" target="_blank" <?=$eventHandler?>>
      <?=$recommendationDivs?>
    </a>
    <div class="tooltip">
      <div id="mrdlib_info_icon">i</div>
      <span class="tooltiptext"><?=$abstract;?></span>
    </div>
  </li>
<?php
  }
?>
</ul>
</div>
<div id="mrdlib_footer">
  <form id="mrdlib_advanced_recommendations_form" onsubmit="update_cookie_setting();">
    <input type="checkbox" name="mrdlib_advanced_recommendations" value="mrdlib_advanced_recommendations_enabled" /><span id="mrdlib_advanced_recommendations_caption"> Enable advanced recommendations. This will set a cookie that allows personalizing your recommendations.</span>
    <input type="submit" value="Submit">
  </form>
  <a href="http://mr-dlib.org/">Powered by <img src="mdl_logo.gif" alt="Mr. DLib: Recommendations-as-a-service for Academia"></a>
  <button id="mrdlib_refresh_button" onclick="get_rec()">Refresh</button>
</div>