<?php

# Class to monitor folders in an area to produce thumbnails
class thumbnailerService
{
	# Define settings
	private function settings ()
	{
		# Available arguments, and their defaults or NULL to represent a required argument
		$settings = array (
			'imageStoreRoot'			=> NULL,	// Non-slash-terminated
			'thumbnailsDirectory'		=> NULL,	// Non-slash-terminated
			'administratorEmail'		=> NULL,
			'filetypes'					=> array ('jpg', 'jpeg', 'tif', 'tiff'),
			'fileRegexp'				=> '/[a-z0-9A-Z]([^/]+)$',	// i.e. /path/to/._07_hires.tif would not get matched
			'logFile'					=> '/thumbnaillog.wri',		// Within $thumbnailsDirectory
			'lockFile'					=> '/thumbnailerLockFile',	// Within $thumbnailsDirectory
			'echoOutput'				=> false,	// Whether to show success on the command line
			'outputFormat'				=> 'jpg',
			'memoryLimit'				=> '4000M',		// Set to match the ImageMagick policy.xml setting
			'maxExecutionTime'			=> 0,
			'limitTotalThisSession'		=> 0,
			'limitTotalBytes'			=> 10 * (1024*1024*1024),	// 10GB
			'watches'					=> array ('/'),
			'knownProblemFiles'			=> array (),	// Fix by adjusting the policies in /etc/ImageMagick-6/policy.xml, e.g. to allow more RAM or larger width/height
			'thumbnailRawFolders'		=> array (),	// Folders where '-raw' files are wanted to be thumbnailed
		);
		
		# Return the settings
		return $settings;
	}
	
	
	# Constructor
	public function __construct ($settings)
	{
		# Set the timezone (PHP 5.3+ requires this)
		ini_set ('date.timezone', 'Europe/London');
		
		# Get the settings
		if (!$this->settings = application::assignArguments ($errors, $settings, $this->settings (), get_class ($this), NULL, $handleErrors = true)) {return false;}
		
		# Force a singleton instance
		$this->lockFile = $this->settings['thumbnailsDirectory'] . $this->settings['lockFile'];
		if (file_exists ($this->lockFile)) {
			$savedAt = filemtime ($this->lockFile);
			$hoursSinceSaved = floor ((time () - $savedAt) / (60*60));	// i.e. 0 if in the last hour, or >0 if stale
			if ($hoursSinceSaved) {
				$message = "The image thumbnailer appears to have a stale lockfile. Please check this out and delete the file\n\n{$this->lockFile}\n\nso that it will run again.";
				application::utf8Mail ($this->settings['administratorEmail'], 'Image thumbnailer - stale lockfile problem', wordwrap ($message), "From: {$this->settings['administratorEmail']}");
			}
			return;
		}
		if (!file_put_contents ($this->lockFile, 'thumbnailer running')) {return;}
		
		# Set up the environment
		ini_set ('memory_limit', $this->settings['memoryLimit']);
		ini_set ('max_execution_time', $this->settings['maxExecutionTime']);	// Set execution time per run
		register_shutdown_function (array ($this, 'shutdown'));
		
		# Check for TIFF support
		if ((in_array ('tif', $this->settings['filetypes'])) || (in_array ('tiff', $this->settings['filetypes']))) {
			if (!extension_loaded ('magickwand') && !extension_loaded ('imagick')) {
				echo "The webserver needs to have the magickwand PHP extension added so that TIF files can be read. This script will not run until this is fixed.\n";
				return false;
			}
		}
		
		# Log startup
		$this->logChange ("Thumbnailer initiated with \$maxExecutionTime = {$this->settings['maxExecutionTime']} and \$limitTotalThisSession = {$this->settings['limitTotalThisSession']} and \$totalBytes = {$this->settings['limitTotalBytes']}");
		
		# Ensure the thumbnails directory is accessible
		if (!is_readable ($this->settings['thumbnailsDirectory']) || !is_writable ($this->settings['thumbnailsDirectory'])) {
			$message = "The thumbnailer could not access the directory {$this->settings['thumbnailsDirectory']} .";
			application::utf8Mail ($this->settings['administratorEmail'], 'Image thumbnailer - thumbnails directory not accessible', wordwrap ($message), "From: {$this->settings['administratorEmail']}");
			return false;
		}
		
		# Create the output directory or end
		#!# Need to change to independent read and write checks
		if (!is_writable ($this->settings['thumbnailsDirectory'] . '/')) {
			umask (0);
			if (!mkdir ($this->settings['thumbnailsDirectory'], 0775)) {
				echo "Cannot create output directory {$this->settings['thumbnailsDirectory']}/\n";
				return false;
			}
			if (!is_writable ($this->settings['thumbnailsDirectory'])) {
				echo "Cannot write to output directory {$this->settings['thumbnailsDirectory']}/\n";
				return false;
			}
		}
		
		# Loop through each watch and check it is readable
		$directories = array ();
		$watches = application::ensureArray ($this->settings['watches']);
		foreach ($watches as $index => $watch) {
			
			# Ensure it the directory is slash terminated
			$watch = $this->settings['imageStoreRoot'] . $this->slashTerminated ($watch);
			
			# Remove if it does not exist and complain
			if (!is_readable ($watch)) {
				echo "The directory {$watch} in the watch list does not exist or could not be read and so is being ignored\n";
				unset ($watches[$index]);
			}
			
			# Replace with the full path and add tree indicator
			$watches[$index] = $watch . '*';
		}
		
		# End if no watches
		if (!$watches) {return false;}
		
		# Get a list of files in these directories
		$files = directories::flattenedFileListingFromArray ($watches, '', $this->settings['filetypes'], true, false, false, $regexpAfterStart = $this->settings['fileRegexp']);
		
		# State and log the number of files
		if ($this->settings['echoOutput']) {echo count ($files) . " files found; now starting checking ...\n";}
		$this->logChange (count ($files) . ' files found; now starting checking ...');
		
		# Loop through each file
		foreach ($files as $fullPath => $file) {
			
			# Skip the thumbnails directories files
			$pregDelimeter = '@';
			if (preg_match ($pregDelimeter . '^' . $this->settings['thumbnailsDirectory'] . $pregDelimeter, $fullPath)) {
				unset ($files[$fullPath]);
				continue;
			}
			
			# Skip unreadable files and complain
			if (!is_readable ($file)) {
				if (file_exists ($file)) {
					echo "Cannot read file {$file} .\n";
					unset ($files[$fullPath]);
				}
				continue;
			}
			
			# Skip '-raw' files where there is a '-master' also; this helps avoid directories being twice the size, which can create performance issues in Windows
			if (substr_count ($fullPath, '-raw.')) {
				$pregDelimeter = '@';
				$master = preg_replace ($pregDelimeter . '(.+)-raw\.([a-z]+)^' . $pregDelimeter, '\1-master.\2', $fullPath);
				if (is_readable ($master)) {
					
					# Allow raws in specific areas
					$rawWanted = false;
					foreach ($this->settings['thumbnailRawFolders'] as $thumbnailRawFolders) {
						if (substr_count ($fullPath, $thumbnailRawFolders)) {
							$rawWanted = true;
						}
					}
					if (!$rawWanted) {
						
						# Remove and skip
						unset ($files[$fullPath]);
						continue;
					}
				}
			}
			
			
			// # Debug:
			// echo "{$fullPath}    {$this->settings['thumbnailsDirectory']}\n";
			
			# Assemble the attributes for this file
			#!# This should be moved into the later phase, to reduce memory and timing issues, e.g. "PHP Warning:  Illegal string offset 'path'"
			$directory = dirname ($file) . '/';
			$pregDelimeter = '@';
			$directoryFromRoot = preg_replace ($pregDelimeter . '^' . $this->settings['imageStoreRoot'] . $pregDelimeter, '', $directory);
			$attributes = array (
				'name' => basename ($file),
				'extension' => substr (strrchr ($file, '.'), 1),
				'directory' => $directory,
				'path' => $directoryFromRoot,
				'size' => filesize ($file),
				'time' => filemtime ($file),
			);
			
			# Replace the filename with the attributes
			$files[$fullPath] = $attributes;
		}
		
		//application::dumpData ($files);
		
		# State the number of files
		if ($this->settings['echoOutput']) {echo count ($files) . " files ready; now starting resizing ...\n";}
		$this->logChange (count ($files) . " files ready; now starting resizing ...");
		
		# Define the sizes
		$sizes = array (
			$this->settings['thumbnailsDirectory'] => array ('width' => 800, 'height' => 800),
		);
		
		# Loop through each file
		$totalThisSession = $this->settings['limitTotalThisSession'];	// Start at quota and work down
		$totalBytes = 0;
		foreach ($sizes as $resizedDirectory => $resizeTo) {
			foreach ($files as $file => $attributes) {
				
				# Skip known problematic files
				if (in_array ($file, $this->settings['knownProblemFiles'])) {continue;}
				
				# End if number of files per session is exceeded
				if ($this->settings['limitTotalThisSession'] && !$totalThisSession) {
					//$this->shutdown ();
					return true;
				}
				
				# End if $totalBytes reached
				if ($this->settings['limitTotalBytes'] && ($totalBytes > $this->settings['limitTotalBytes'])) {
					//$this->shutdown ();
					return true;
				}
				
				# Determine the intended thumbnail filename
				$thumbnail = $resizedDirectory . $attributes['path'] . $attributes['name'];
				$pregDelimeter = '@';
				$thumbnail = preg_replace ($pregDelimeter . '\.' . $attributes['extension'] . '$' . $pregDelimeter, '.' . $this->settings['outputFormat'], $thumbnail);
				
				# If there is already a thumbnail, skip this file
				#!# Detect newer files by doing a date comparison - the lack of this seems to cause confusion
				if (file_exists ($thumbnail)) {continue;}
				
				# Skip if the file has been moved in the intervening period between the file listing and this current line in this script
				if (!file_exists ($file)) {continue;}
				
				# Get the image sizes
				if (!$result = getimagesize ($file)) {echo "Problem reading file {$file}.";}
				list ($width, $height, $ignore1, $ignore2) = $result;
				
				# Determine height and width
				$newWidth = ($height >= $width ? $resizeTo['width'] : '');
				$newHeight = ($width > $height ? $resizeTo['height'] : '');
				
				# Create the thumbnail, specifying the greater of the width/height or continue to next
				#!# Need to maintain EXIF data and sort out colourspace issues etc: compare *-master.tif with the generated .jpg
				if (!$result = image::resize ($file, $this->settings['outputFormat'], $newWidth, $newHeight, $thumbnail)) {
					echo "\n" . $file . "\n";
					continue;
				}
				
				# Confirm success and log it, and increment the size counter
				if ($result) {
					$this->logChange ("Successfully created resized file {$thumbnail}");
					$totalThisSession--;
					$totalBytes += $attributes['size'];
				}
			}
		}
	}
	
	
	# Function to complete ending
	private function shutdown ()
	{
		$this->logChange ('Ended this run');
		unlink ($this->lockFile);
		return true;
	}
	
	
	# Function to log changes
	private function logChange ($message)
	{
		# Add newline
		$message .= "\n";
		
		# Add the date
		$message = date ('Y-m-d H:i:s') . '  ' . $message;
		
		# Determine the logfile
		$logFile = $this->settings['thumbnailsDirectory'] . $this->settings['logFile'];
		
		# Log the change
		file_put_contents ($logFile, $message, FILE_APPEND);
		
		# Show the message if wanted
		if ($this->settings['echoOutput']) {echo $message;}
	}
	
	
	# Function to slash-terminate a directory if it is not already
	private function slashTerminated ($directory)
	{
		# Return the result
		return ((substr ($directory, -1) == '/') ? $directory : $directory . '/');
	}
}

?>
