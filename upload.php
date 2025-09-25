<?php
require 'vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$db_host = 'database-1.c1qcq8eoe05s.ap-south-1.rds.amazonaws.com';
$db_username = 'root';
$db_password = 'piyush07';
$db_name = 'attendance_db';

// AWS S3 configuration
$bucket = 'thala0707';
$cloudfront_domain = 'd3rj2ycxmyu7ck.cloudfront.net';
$aws_access_key = '**********';
$aws_secret_key = '*************';

// Instantiate an Amazon S3 client
$s3Client = new S3Client([
    'version' => 'latest',
    'region'  => 'ap-south-1',
    'credentials' => [
        'key'    => $aws_access_key,
        'secret' => $aws_secret_key
    ]
]);

// Set database connection
$conn = mysqli_connect($db_host, $db_username, $db_password, $db_name);

if (!$conn) {
    die(json_encode(["status" => "error", "message" => "Database connection failed: " . mysqli_connect_error()]));
}

// Set header for JSON response
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if it's an attendance submission or image upload
    if (isset($_POST["employee_id"])) {
        // Process attendance form
        $employee_id = mysqli_real_escape_string($conn, $_POST["employee_id"]);
        $employee_name = mysqli_real_escape_string($conn, $_POST["employee_name"]);
        $attendance_type = mysqli_real_escape_string($conn, $_POST["attendance_type"]);
        $notes = isset($_POST["notes"]) ? mysqli_real_escape_string($conn, $_POST["notes"]) : '';
        $date = date('Y-m-d H:i:s');
        
        // Check if attendance already marked for today
        $check_sql = "SELECT id FROM attendance WHERE employee_id = '$employee_id' AND DATE(date) = CURDATE()";
        $check_result = mysqli_query($conn, $check_sql);
        
        if (mysqli_num_rows($check_result) > 0) {
            echo json_encode(["status" => "error", "message" => "Attendance already marked for today"]);
        } else {
            // Insert into attendance table
            $sql = "INSERT INTO attendance (employee_id, employee_name, attendance_type, notes, date) 
                    VALUES ('$employee_id', '$employee_name', '$attendance_type', '$notes', '$date')";
            
            if (mysqli_query($conn, $sql)) {
                echo json_encode(["status" => "success", "message" => "Attendance recorded successfully"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Database error: " . mysqli_error($conn)]);
            }
        }
        
    } elseif (isset($_POST["name"]) && isset($_FILES["anyfile"])) {
        // Process image upload form
        if (empty($_POST["name"]) || empty($_POST["caption"])) {
            echo json_encode(["status" => "error", "message" => "Name and caption are required."]);
            exit;
        }
        
        if ($_FILES["anyfile"]["error"] != 0) {
            echo json_encode(["status" => "error", "message" => "File upload error: " . $_FILES["anyfile"]["error"]]);
            exit;
        }
        
        $allowed = [
            "jpg"  => "image/jpeg",
            "jpeg" => "image/jpeg",
            "gif"  => "image/gif",
            "png"  => "image/png"
        ];

        $filename = $_FILES["anyfile"]["name"];
        $filetype = $_FILES["anyfile"]["type"];
        $filesize = $_FILES["anyfile"]["size"];

        // Validate file extension
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!array_key_exists($ext, $allowed)) {
            echo json_encode(["status" => "error", "message" => "Please select a valid file format (JPG, JPEG, GIF, PNG)."]);
            exit;
        }

        // Validate MIME type
        if (!in_array($filetype, $allowed)) {
            echo json_encode(["status" => "error", "message" => "Invalid file type."]);
            exit;
        }

        // Validate file size (10 MB max)
        $maxsize = 10 * 1024 * 1024;
        if ($filesize > $maxsize) {
            echo json_encode(["status" => "error", "message" => "File size is larger than the 10MB limit."]);
            exit;
        }

        // Ensure uploads directory exists
        if (!is_dir("uploads")) {
            mkdir("uploads", 0755, true);
        }

        // Generate unique filename to prevent overwrites
        $newFilename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        $uploadPath = "uploads/" . $newFilename;

        if (move_uploaded_file($_FILES["anyfile"]["tmp_name"], $uploadPath)) {
            $file_Path = __DIR__ . '/uploads/' . $newFilename;
            $key = basename($file_Path);

            try {
                // Upload file to S3
                $result = $s3Client->putObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'Body'   => fopen($file_Path, 'r'),
                    'ACL'    => 'public-read',
                ]);

                $urls3 = $result->get('ObjectURL');
                
                // Replace with CloudFront domain
                $cfurl = str_replace(
                    "https://{$bucket}.s3.ap-south-1.amazonaws.com",
                    "https://{$cloudfront_domain}",
                    $urls3
                );

                // Save details in MySQL
                $name = mysqli_real_escape_string($conn, $_POST["name"]);
                $caption = mysqli_real_escape_string($conn, $_POST["caption"]);
                
                $stmt = $conn->prepare("INSERT INTO posts (name, caption, url, cfurl) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $name, $caption, $urls3, $cfurl);
                
                if ($stmt->execute()) {
                    echo json_encode([
                        "status" => "success", 
                        "message" => "Image uploaded successfully",
                        "s3_url" => $urls3,
                        "cf_url" => $cfurl
                    ]);
                } else {
                    echo json_encode(["status" => "error", "message" => "Database Error: " . $stmt->error]);
                }

                $stmt->close();

            } catch (AwsException $e) {
                echo json_encode(["status" => "error", "message" => "Error uploading to S3: " . $e->getMessage()]);
                // Clean up local file if S3 upload fails
                unlink($uploadPath);
            }

        } else {
            echo json_encode(["status" => "error", "message" => "File upload failed. Please try again."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid request parameters."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}

mysqli_close($conn);
?>
