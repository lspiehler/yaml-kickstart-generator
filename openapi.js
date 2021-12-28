var spec = {
	"swagger": "2.0",
	"info": {
	  "description": "This is an API service for generating RHEL kickstart files for use with this Ansible playbook <a href=\"/https://github.com/Sapphire-Health/ansible-vmware-build.git\">https://github.com/Sapphire-Health/ansible-vmware-build.git</a>",
	  "version": "1.0.0",
	  "title": "RHEL Kickstart Generator",
	  "termsOfService": "http://swagger.io/terms/",
	  "contact": {
		"email": "lyas.spiehler@sapphirehealth.org"
	  },
	  "license": {
		"name": "Apache 2.0",
		"url": "http://www.apache.org/licenses/LICENSE-2.0.html"
	  }
	},
	"host": "kickstart.sapphirehealth.org",
	"basePath": "/",
	"tags": [
	  {
		"name": "rhel.php",
		"description": "Request RHEL kickstart",
		"externalDocs": {
		  "description": "Find out more",
		  "url": "https://github.com/Sapphire-Health/ansible-vmware-build.git"
		}
	  }
	],
	"schemes": [
	  "https"
	],
	"paths": {
	  "/rhel.php": {
		"post": {
		  "tags": [
			"rhel.php"
		  ],
		  "summary": "Request RHEL kickstart",
		  "description": "",
		  "consumes": [
			"application/json"
		  ],
		  "produces": [
			"application/json"
		  ],
		  "parameters": [
			{
			  "in": "body",
			  "name": "body",
			  "description": "RHEL kickstart generator",
			  "required": true,
			  "schema": {
				"$ref": "#/definitions/Request"
			  }
			}
		  ],
		  "responses": {
			"405": {
			  "description": "Invalid input"
			}
		  }
		}
	  }
	},
	"definitions": {
		"parts": {
			"properties": {
				"part": {
					"type": "string"
				},
				"size_mb": {
					"type": "integer"
				},
				"mountpoint": {
					"type": "string"
				},
				"fstype": {
					"type": "string"
				}
			}
		},
		"vgs": {
			"properties": {
				"name": {
					"type": "string"
				},
				"pvs": {
					"type": "array",
					"items": "string"
				},
				"lvs": {
					"type": {
						"$ref": "#/definitions/lvs"
					}
				}
			}
		},
		"lvs": {
			"properties": {
				"name": {
					"type": "string"
				},
				"size_mb": {
					"type": "integer"
				},
				"mountpoint": {
					"type": "string"
				},
				"fstype": {
					"type": "string"
				}
			}
		},
		"logical_storage": {
			"properties": {
				"vgs": {
					"type": "array",
					"items": {
						"$ref": "#/definitions/vgs"
					}
				}
			}
		},
		"physical_storage": {
			"properties": {
				"disk": {
					"type": "string"
				},
				"boot": {
					"type": "boolean"
				},
				"lvm": {
					"type": "boolean"
				},
				"size_mb": {
					"type": "integer"
				},
				"type": {
					"type": "string"
				},
				"datastore": {
					"type": "string"
				},
				"controller_number": {
					"type": "integer"
				},
				"unit_number": {
					"type": "integer"
				},
				"controller_type": {
					"type": "string"
				},
				"parts": {
					"type": "array",
					"items": {
						"$ref": "#/definitions/physical_storage"
					}
				}
			}
		},
	  "Request": {
		"type": "object",
		"properties": {
		  "ks_stateless": {
			"type": "bool",
			"example": "true"
		  },
		  "ks_rootpw": {
			"type": "string",
			"example": "$5$3bMwRIV.qGoxqsqk$GJuLfFt9wscxO1ILJJ3QACf7tZvToiGa1KH9HPlbVGA"
		  },
		  "ks_user": {
			"type": "object",
			"properties": {
				"name": {
					"type": "string"
				},
				"shell": {
					"type": "string"
				},
				"uid": {
					"type": "integer"
				},
				"gid": {
					"type": "integer"
				},
				"ssh_pub_key": {
					"type": "string"
				}
			},
			"example": {
				"name": "ansible",
				"shell": "/bin/bash",
				"uid": 999999,
				"gid": 999999,
				"ssh_pub_key": "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQCtvzax34Ka15l9M8b5uRRcR+YEPdb5//oW/aKS6vhmNlmkeYlfvvfMKBLdeSGsSlybESlqceK6V1bcy03rgRdciXO9EvKMQ9pKpXSgvIzWIjarlQLPtvcbT8ru9g/4veihAvw+tLIS6qgwDIPzYu+DnTipMTIpXQL3fc5AbRMDmw=="
			  }
		  },
		  "ks_lang": {
			"type": "string",
			"example": "en_US.UTF-8"
		  },
		  "ks_keyboard": {
			"type": "string",
			"example": "us"
		  },
		  "ks_text": {
			"type": "string",
			"example": "true"
		  },
		  "ks_time": {
			"type": "object",
			"properties": {
				"timezone": {
					"type": "string"
				},
				"utc": {
					"type": "bool"
				},
				"ntpservers": {
					"type": "array",
					"items": {
						"type": "string"
					}
				}
			},
			"example": {
				"timezone": "America/Chicago",
				"utc": true,
				"ntpservers": [
					"time1.google.com",
					"time2.google.com",
					"time3.google.com",
					"time4.google.com"
				]
			}
		  },
		  "ks_packages": {
			"type": "array",
			"items": {
			  "type": "string"
			},
			"example": [
				"@^minimal-environment",
				"kexec-tools",
				"vim",
				"nfs-utils"
			  ],
		  },
		  "vm_storage": {
			"type": "object",
			"properties": {
				"vm_storage": {
					"type": "object",
					"properties": {
						"physical": {
							"type": "array",
							"items": {
								"$ref": "#/definitions/physical_storage"
							}
						},
						"logical": {
							"type": "object",
							"items": {
								"$ref": "#/definitions/logical_storage"
							}
						}
					}
				}
			},
			"example": {
				"physical": [
					{
						"disk": "/dev/sda",
        				"boot": true,
						"size_mb": 2048,
						"type": "thin",
						"datastore": "vmware_datastore_name",
						"controller_number": 0,
						"unit_number": 0,
						"controller_type": "paravirtual",
						"parts": [
							{
								"part": "/dev/sda1",
								"size_mb": 1024,
								"mountpoint": "/boot",
								"fstype": "xfs"
							},
							{
								"part": "/dev/sda2",
								"size_mb": 600,
								"mountpoint": "/boot/efi",
								"fstype": "vfat"
							}
						]
					},
					{
						"disk": "/dev/sdb",
						"size_mb": 102400,
						"lvm": true,
						"type": "thin",
						"datastore": "VMFS6_02",
						"controller_number": 0,
						"unit_number": 1,
						"controller_type": "paravirtual",
						"parts": []
					  }
				],
				"logical": {
					"vgs": [
						{
						  "name": "sys_vg",
						  "pvs": [
							"/dev/sdb"
						  ],
						  "lvs": [
							{
							  "name": "root_lv",
							  "size_mb": 30720,
							  "fstype": "xfs",
							  "mountpoint": "/"
							},
							{
							  "name": "var_lv",
							  "size_mb": 10240,
							  "fstype": "xfs",
							  "mountpoint": "/"
							},
							{
							  "name": "swap_lv",
							  "size_mb": 4096,
							  "fstype": "swap",
							  "mountpoint": "swap"
							}
						  ]
						}
					]
				}
			}
		  },
		}
	  },
	  "Response": {
		"type": "object",
		"properties": {
		  "code": {
			"type": "integer",
			"format": "int32"
		  },
		  "type": {
			"type": "string"
		  },
		  "message": {
			"type": "string"
		  }
		}
	  }
	},
	"externalDocs": {
	  "description": "Find out more about Swagger",
	  "url": "http://swagger.io"
	}
  }